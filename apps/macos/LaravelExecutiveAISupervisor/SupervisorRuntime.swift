// The Supervisor's boot sequence (ADR-001 D3, ADR-003 D2/D3; services.md §2):
//
//   1. resolve the installed runtime (`runtime/current`) and the port preflight recorded;
//   2. make sure `APP_KEY` and `K` exist in the Keychain (first boot generates them);
//   3. start the secrets broker socket and the XPC control listener — before any child, so a
//      verification failure is still observable and recoverable from the GUI;
//   4. verify the runtime directory (checksums + signature) — fail closed: nothing is spawned,
//      every service shows `failed` with the reason, "Start services" re-verifies;
//   5. run `artisan migrate --force` as a one-shot and wait for it — forward-only, idempotent,
//      and a hard precondition for the health contract's `database_migrations`; fails closed the
//      same way as step 4;
//   6. spawn the three children and run the health monitor.
//
// Steps 4 and 5 together are the `beforeStart` the control surface re-runs for "Start services",
// so a repair followed by a retry from the GUI goes down exactly the same path as a boot.
//
// `SupervisorRuntime` is the composition root `main.swift` drives; everything it composes is
// behind a protocol with a test double, and the pieces are tested individually.

import Foundation

struct SupervisorConfiguration: Sendable {
    var identity: SupervisorIdentity
    var paths: InstallPaths
    var keychain: any KeychainStore
    var verifier: PackageVerifier
    var peerVerifier: any PeerVerifying
    var authenticator: any DeviceOwnerAuthenticating
    var allowUnsignedXPCPeers: Bool
    var supervision: ServiceSupervisor.Configuration
    var makeProbe: @Sendable (_ port: Int, _ grant: SupervisorGrant) -> any HealthProbing
    var liveInterval: Duration = .seconds(5)
    var readyInterval: Duration = .seconds(30)
    /// How long `artisan migrate --force` may take before it is stopped and the boot fails closed
    /// (services.md §2 step 6). Two orders of magnitude above the estimated cost of the bundled
    /// migrations, because the only job of the bound is to turn a wedged child into a reason the
    /// GUI can show instead of a boot that never finishes.
    var migrationTimeout: Duration = .seconds(120)
    var random: @Sendable (Int) -> Data = SecureRandom.bytes
    var now: @Sendable () -> Date = { Date() }
    /// Tests only: `sun_path` holds 103 bytes and the system temporary directory is long.
    var secretsSocketOverride: URL?
}

enum SupervisorBootError: Error, Equatable, Sendable {
    case noInstalledRuntime
    case noRecordedPort
    case secretsUnavailable(String)
    case verificationFailed(String)
    case migrationsFailed(String)
    case brokerFailed(String)

    /// Short, sanitised, fit for the menu bar (no path, no user name).
    var userFacingReason: String {
        switch self {
        case .noInstalledRuntime: return "No installed runtime was found; run the installer again."
        case .noRecordedPort: return "The installer did not record a loopback port; run the installer again."
        case .secretsUnavailable(let reason): return "The Keychain is not available to the Supervisor (\(reason))."
        case .verificationFailed(let reason): return "The installed runtime failed verification (\(reason))."
        // #124: names what was being waited for, then answers the only question the person
        // actually has — "did I lose anything" — rather than describing the mechanism.
        //
        // It must **not** say the schema is unchanged. Laravel's `Migrator.runPending()` is a
        // plain `foreach` over `runUp()` with no try/catch and no rollback of earlier
        // migrations, and `runUp()` calls `repository->log()` as soon as each one succeeds
        // (vendor/laravel/framework/src/Illuminate/Database/Migrations/Migrator.php:210, :258).
        // So a migration throwing at number N leaves 1…N-1 applied *and recorded* — which is one
        // of the two paths that produce this sentence (the other, a timeout or a spawn failure,
        // really did change nothing). Claiming "nothing changed" to someone who then finds a
        // part-built database is worse than saying nothing: the next thing they decide is whether
        // it is safe to retry, and a message they do not trust sends them looking for a way to
        // "clean up", which is the one action that could lose data.
        //
        // What is true on both paths: every migration here is `up()`-only and a rollback restores
        // a verified snapshot rather than running `down()` (ADR-002 D5), so a partial run leaves
        // the database part-built and never damaged, and `migrate --force` resumes at N. That
        // forward-only property is pinned by `testSecondBootOverAnAlreadyMigratedDatabase…` and
        // `testStartServicesReRunsTheMigrationInTheSameProcess`, so this sentence rests on a test
        // rather than on a hope.
        case .migrationsFailed(let reason): return "The database migrations did not finish (\(reason)); no service was started. Nothing was deleted and no data was lost. Trying again continues from where it stopped."
        case .brokerFailed(let reason): return "The secrets broker could not start (\(reason))."
        }
    }
}

/// What `install/checkpoint.json` contributes: the loopback port (installer.md §3).
enum SupervisorBootPlan {
    static func port(from checkpointURL: URL) -> Int? {
        guard let data = try? Data(contentsOf: checkpointURL),
              let object = try? JSONSerialization.jsonObject(with: data) as? [String: Any],
              let preflight = object["preflight"] as? [String: Any],
              let port = preflight["port"] as? Int, (1...65535).contains(port)
        else {
            return nil
        }

        return port
    }

    static func installedRuntime(in paths: InstallPaths) -> InstalledRuntime? {
        guard let version = paths.currentRuntimeVersion() else {
            return nil
        }

        let directory = paths.runtimeDirectory(version: version)
        var isDirectory: ObjCBool = false

        guard FileManager.default.fileExists(atPath: directory.path, isDirectory: &isDirectory), isDirectory.boolValue else {
            return nil
        }

        return InstalledRuntime(version: version, directory: directory)
    }

    static func secretsSocket(in paths: InstallPaths) -> URL {
        paths.runDirectory.appending(path: "secrets.sock")
    }
}

actor SupervisorRuntime {
    private let configuration: SupervisorConfiguration
    private var supervisor: ServiceSupervisor?
    private var broker: SecretsBrokerServer?
    private var listener: SupervisorControlListener?
    private var monitor: Task<Void, Never>?
    private var control: SupervisorControlService?

    init(configuration: SupervisorConfiguration) {
        self.configuration = configuration
    }

    /// Boots and keeps running until `shutdown()`; the error cases that leave the Supervisor
    /// unable to serve anything at all (no runtime, no port, no Keychain) are thrown so
    /// `main.swift` can log them and exit — launchd's `KeepAlive` + `ThrottleInterval` then
    /// retries once every 10 s, which is the right cadence for "the installer has not finished".
    func boot() async throws {
        let paths = configuration.paths
        let identity = configuration.identity
        let log = SupervisorLog.boot

        guard let runtime = SupervisorBootPlan.installedRuntime(in: paths) else {
            throw SupervisorBootError.noInstalledRuntime
        }

        guard let port = SupervisorBootPlan.port(from: paths.checkpointURL) else {
            throw SupervisorBootError.noRecordedPort
        }

        let secrets = SupervisorSecrets(store: configuration.keychain, random: configuration.random, now: configuration.now)
        let key: IpcKey

        do {
            try secrets.ensureAppKey()
            key = try secrets.ensureIpcKey()
        } catch {
            throw SupervisorBootError.secretsUnavailable("\(error)")
        }

        let socket = configuration.secretsSocketOverride ?? SupervisorBootPlan.secretsSocket(in: paths)
        let specs = ServiceSpec.coreServices(runtime: runtime, paths: paths, port: port, secretsSocket: socket)
        var supervision = configuration.supervision
        let logs = paths.logs
        supervision.makeLog = { name in RotatingLogFile(url: logs.appending(path: "\(name.rawValue).log")) }

        let supervisor = ServiceSupervisor(specs: specs, configuration: supervision)
        self.supervisor = supervisor

        let pids = PidRegistry()
        await supervisor.addObserver { statuses in
            pids.update(Set(statuses.compactMap(\.pid)))
        }

        let brokerLog = SupervisorLog.broker
        let brokerService = SecretsBrokerService(store: configuration.keychain) { entry in
            brokerLog.notice("secrets \(entry.operation, privacy: .public) service=\(entry.service ?? "-", privacy: .private) account=\(entry.account ?? "-", privacy: .private) pid=\(entry.pid, privacy: .private) result=\(entry.result, privacy: .public)")
        }

        let broker = SecretsBrokerServer(
            socketPath: socket,
            service: brokerService,
            verifier: configuration.peerVerifier,
            spawnedPids: { pids.current },
            rejected: { reason in brokerLog.error("secrets peer rejected: \(reason, privacy: .private)") }
        )

        do {
            try broker.start()
        } catch {
            throw SupervisorBootError.brokerFailed("\(error)")
        }

        self.broker = broker

        let verifier = configuration.verifier
        let verify: @Sendable () async throws -> Void = {
            do {
                _ = try await Task.detached(priority: .userInitiated) { try verifier.verify(runtimeDirectory: runtime.directory) }.value
            } catch let error as PackageVerificationError {
                throw SupervisorBootError.verificationFailed(error.userFacingReason)
            } catch {
                throw SupervisorBootError.verificationFailed("unexpected error")
            }
        }

        // The one-shot that has to finish before anything is supervised. Nothing else in the
        // product applies migrations, and `database_migrations` ("zero pending") is a hard
        // dependency of every other `ready` check (health-contract.md §2), so a fresh install
        // could otherwise never become ready. `migrate --force` is forward-only and idempotent:
        // on a database that is already current it applies nothing and exits 0, which is why
        // running it on every boot — and on every "Start services" — is safe.
        let migrations = OneShotSpec.migrations(runtime: runtime, paths: paths, port: port, secretsSocket: socket)
        let migrationLog: any LogSink = RotatingLogFile(url: logs.appending(path: "\(migrations.logName).log"))
        let migrationTimeout = configuration.migrationTimeout
        let migrate: @Sendable () async throws -> Void = {
            let exit: ServiceExit
            SupervisorLog.boot.notice("applying database migrations")

            do {
                exit = try await ChildProcess(spec: migrations, log: migrationLog).run(timeout: migrationTimeout)
            } catch ChildProcessError.timedOut(_, let seconds) {
                throw SupervisorBootError.migrationsFailed("no result after \(seconds) seconds")
            } catch {
                throw SupervisorBootError.migrationsFailed("could not be started")
            }

            guard exit.reason == .exited(status: 0) else {
                // `migrate.log` has the artisan output; the reason here is status-only.
                throw SupervisorBootError.migrationsFailed(exit.description)
            }
        }

        let prepare: @Sendable () async throws -> Void = {
            try await verify()
            try await migrate()
        }

        let control = SupervisorControlService(
            secrets: secrets,
            key: key,
            supervisor: supervisor,
            port: port,
            authenticator: configuration.authenticator,
            now: configuration.now,
            random: configuration.random,
            beforeStart: prepare
        )
        self.control = control

        let listener = SupervisorControlListener(
            machServiceName: identity.machServiceName,
            requirement: identity.codeSigningRequirement,
            service: control,
            allowUnsignedPeers: configuration.allowUnsignedXPCPeers
        )
        listener.start()
        self.listener = listener

        let grant = SupervisorGrant.derive(key: key, now: configuration.now(), random: configuration.random)
        startMonitor(port: port, grant: grant, supervisor: supervisor)

        await control.onKeyChanged { [weak self] newKey in
            guard let self else {
                return
            }

            Task {
                await self.rotateGrant(key: newKey, port: port, supervisor: supervisor)
            }
        }

        do {
            try await prepare()
        } catch let error as SupervisorBootError {
            log.error("boot preparation failed: \(error.userFacingReason, privacy: .public)")
            await supervisor.markAllFailed(reason: error.userFacingReason)
            return
        }

        log.notice("runtime verified and migrations applied; starting services on port \(port, privacy: .public)")
        await supervisor.startAll()
    }

    func statuses() async -> [ServiceStatus] {
        await supervisor?.statuses() ?? []
    }

    /// The live control surface — what the GUI reaches over XPC (ipc-contract §7). Exposed so the
    /// boot tests can drive "Start services" exactly as the menu bar does, including the re-run of
    /// steps 5–6; the XPC transport itself is covered by `SupervisorControlServiceTests` and
    /// `SupervisorControlXPC`.
    func controlSurface() -> SupervisorControlService? {
        control
    }

    func shutdown() async {
        monitor?.cancel()
        monitor = nil
        listener?.stop()
        listener = nil
        broker?.stop()
        broker = nil
        await supervisor?.stopAll()
    }

    private func startMonitor(port: Int, grant: SupervisorGrant, supervisor: ServiceSupervisor) {
        monitor?.cancel()

        let probe = configuration.makeProbe(port, grant)
        let monitor = ServiceHealthMonitor(probe: probe, supervisor: supervisor, liveInterval: configuration.liveInterval, readyInterval: configuration.readyInterval)
        self.monitor = Task {
            await monitor.run()
        }
    }

    private func rotateGrant(key: IpcKey, port: Int, supervisor: ServiceSupervisor) {
        let grant = SupervisorGrant.derive(key: key, now: configuration.now(), random: configuration.random)
        startMonitor(port: port, grant: grant, supervisor: supervisor)
    }
}

/// The live child pids, updated from the supervisor's observer and read per broker connection.
final class PidRegistry: Sendable {
    private let lock = NSLock()
    nonisolated(unsafe) private var pids = Set<pid_t>()

    func update(_ pids: Set<pid_t>) {
        lock.withLock { self.pids = pids }
    }

    var current: Set<pid_t> {
        lock.withLock { pids }
    }
}
