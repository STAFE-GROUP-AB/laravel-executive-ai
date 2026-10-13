// The migration one-shot (services.md §2 step 5, ADR-001 D3): `artisan migrate --force` runs to
// completion before any supervised child, with the children's own environment, and a non-zero
// exit spawns nothing.
//
// The simulated `frankenphp` is a shell script that appends its own argv to
// `$LEA_LOG_DIR/spawns.txt`, so the order and the exact count of spawns are read from the real
// spawn path rather than from a double. Nothing here touches launchd, the Keychain or XPC.
//
// The database commands that would destroy the owner's data — `migrate:fresh`,
// `migrate:refresh`, `migrate:rollback`, `db:wipe` — are forbidden by installer.md §3 and threat
// model T-72, and `testMigrationArgumentsArePinnedAndForwardOnly` is the regression guard.

import Foundation
import XCTest
@testable import LaravelExecutiveAI

final class SupervisorMigrationsTests: XCTestCase {
    private var root: URL!
    private var paths: InstallPaths!

    override func setUpWithError() throws {
        root = FileManager.default.temporaryDirectory.appending(path: "lxai-mig-\(UUID().uuidString)")
        try FileManager.default.createDirectory(at: root, withIntermediateDirectories: true)
        paths = InstallPaths.rooted(at: root)
    }

    override func tearDown() {
        try? RuntimeInstaller.makeWritable(root)
        try? FileManager.default.removeItem(at: root)
    }

    // MARK: - The spec itself

    private func specs() -> (migrations: OneShotSpec, services: [ServiceSpec]) {
        let runtime = InstalledRuntime(version: "0.1.0", directory: paths.runtimeDirectory(version: "0.1.0"))
        let socket = paths.applicationSupport.appending(path: "run/secrets.sock")

        return (
            OneShotSpec.migrations(runtime: runtime, paths: paths, port: 8787, secretsSocket: socket),
            ServiceSpec.coreServices(runtime: runtime, paths: paths, port: 8787, secretsSocket: socket)
        )
    }

    /// The whole point of the guard: the argument vector is pinned literally, and no command that
    /// drops, resets or rolls back data can appear in it.
    func testMigrationArgumentsArePinnedAndForwardOnly() {
        let spec = specs().migrations

        XCTAssertEqual(spec.arguments, ["php-cli", "app/artisan", "migrate", "--force"])
        XCTAssertEqual(spec.executable.lastPathComponent, "frankenphp")
        XCTAssertEqual(spec.logName, "migrate")
        XCTAssertEqual(spec.workingDirectory, spec.environment["LEA_RUNTIME_DIR"].map { URL(fileURLWithPath: $0) })
        XCTAssertTrue(spec.arguments.contains("--force"), "there is no tty to confirm the production guard on")

        // Destructive variants are forbidden anywhere in the vector (installer.md §3, T-72).
        for forbidden in ["migrate:fresh", "migrate:refresh", "migrate:rollback", "migrate:reset", "db:wipe", "db:seed"] {
            XCTAssertFalse(spec.arguments.contains { $0.contains(forbidden) }, "\(forbidden) must never be spawned")
        }

        // `migrate` is the only artisan command word: nothing is chained after it.
        XCTAssertEqual(spec.arguments.filter { $0.hasPrefix("migrate") }, ["migrate"])
        XCTAssertFalse(spec.arguments.contains { $0.lowercased().contains("drop") || $0.lowercased().contains("truncate") })
    }

    /// Identical to what the three children get — one dictionary, built once (ADR-003 D7 row 12).
    func testMigrationEnvironmentIsExactlyTheChildEnvironment() throws {
        let (migrations, services) = specs()

        for service in services {
            XCTAssertEqual(migrations.environment, service.environment, "\(service.name) and the one-shot must share one environment")
        }

        for value in migrations.environment.values {
            XCTAssertFalse(value.hasPrefix("base64:"), "no APP_KEY in the environment")

            for secret in try ServicesTestSupport.secretsCorpusValues() {
                XCTAssertFalse(value.contains(secret), "environment carries a corpus value")
            }
        }
    }

    // MARK: - Boot

    func testBootRunsMigrationsOnceBeforeAnyChild() async throws {
        try installRuntime(frankenphp: Self.recordingFrankenphp)
        let keychain = InMemoryKeychainStore()
        let runtime = SupervisorRuntime(configuration: configuration(keychain: keychain, socketLabel: "ok"))

        try await runtime.boot()

        // `boot()` returns from `startAll()`, which has spawned all three children by then; the
        // children die at once and go through the bounded restart path, so wait for the first
        // four records rather than assuming the writes have landed.
        let spawns = paths.logs.appending(path: "spawns.txt")
        let spawned = await ServicesTestSupport.waitUntil { Self.lines(at: spawns).count >= 4 }
        XCTAssertTrue(spawned, "migration + three children were spawned, got \(Self.lines(at: spawns))")

        let lines = spawnLines()

        guard lines.count >= 4 else {
            return XCTFail("expected the migration and three children, got \(lines)")
        }

        // The one-shot had exited before the first child was launched, so its record cannot be
        // anything but the first. The three children write theirs from three processes, so their
        // order among themselves is not ours to assert.
        XCTAssertEqual(lines[0], Self.migrateCommand, "the migration runs before anything else")
        XCTAssertEqual(lines.filter { $0 == Self.migrateCommand }.count, 1, "exactly once per boot")

        // Specifically before the **core**, not merely before the worker and the scheduler: the
        // core is what serves `/health/ready`, and `database_migrations` gates `ready` and
        // therefore the installer's `HEALTH_CHECK`/`READY` (health-contract.md §2). A core that
        // began serving before the schema existed would answer `ready: false` on a good install.
        let core = try XCTUnwrap(lines.firstIndex(of: "run --config Caddyfile"), "the core was spawned: \(lines)")
        let migration = try XCTUnwrap(lines.firstIndex(of: Self.migrateCommand))
        XCTAssertLessThan(migration, core, "the migration must finish before the core is spawned: \(lines)")

        XCTAssertTrue(lines.dropFirst().contains { $0.hasPrefix("php-cli app/artisan queue:work database") }, "\(lines)")
        XCTAssertTrue(lines.dropFirst().contains("php-cli app/artisan schedule:work"), "\(lines)")

        // The one-shot's output lands in the Supervisor's own log directory. Waited for, not read
        // at once: `ChildProcess` reports the exit from the termination handler while a
        // `readabilityHandler` invocation may still be in flight, so "the process left" does not
        // mean "every byte is in the file" (`SupervisorRuntimeTests` waits on `core.log` for the
        // same reason). Nothing in the product waits on a log either.
        let migrateLog = self.migrateLog
        let logged = await ServicesTestSupport.waitUntil {
            Self.text(at: migrateLog).contains("simulated runtime: php-cli app/artisan migrate --force")
        }
        XCTAssertTrue(logged, "the one-shot's stdout reaches migrate.log: \(Self.text(at: migrateLog))")

        await runtime.shutdown()
    }

    func testFailingMigrationSpawnsNoChildAndIsVisible() async throws {
        try installRuntime(frankenphp: Self.failingMigrationFrankenphp)
        let keychain = InMemoryKeychainStore()
        let configuration = configuration(keychain: keychain, socketLabel: "fail")
        let runtime = SupervisorRuntime(configuration: configuration)

        try await runtime.boot()

        let statuses = await runtime.statuses()
        XCTAssertEqual(statuses.map(\.name), [.core, .worker, .scheduler])

        for status in statuses {
            XCTAssertEqual(status.state, .failed)
            XCTAssertNil(status.pid)
            XCTAssertEqual(status.detail, "The database migrations did not finish (exited with status 1); no service was started. Nothing was deleted and no data was lost. Trying again continues from where it stopped.")
        }

        // Nothing but the migration was ever spawned, and it was spawned once.
        XCTAssertEqual(spawnLines(), [Self.migrateCommand])

        // The reason is sanitised: no path, no user name, no SQL from the failure.
        let detail = try XCTUnwrap(statuses.first?.detail)
        XCTAssertFalse(detail.contains(root.path))
        XCTAssertFalse(detail.contains(NSUserName()))
        XCTAssertFalse(detail.contains("SQLSTATE"))

        // The artisan output is still readable where an owner (or a support bundle) looks for it.
        // Waited for, for the reason in `testBootRunsMigrationsOnceBeforeAnyChild`: the exit is
        // reported before an in-flight stderr read has necessarily appended.
        let migrateLog = self.migrateLog
        let logged = await ServicesTestSupport.waitUntil { Self.text(at: migrateLog).contains("SQLSTATE[HY000]") }
        XCTAssertTrue(logged, "the failure's stderr reaches migrate.log: \(Self.text(at: migrateLog))")

        // The broker and the XPC control surface stay up, so the GUI can show the failure and
        // "Start services" can retry the same preparation.
        let socket = try XCTUnwrap(configuration.secretsSocketOverride)
        XCTAssertTrue(FileManager.default.fileExists(atPath: socket.path), "broker socket missing at \(socket.path)")

        // The installer's and the menu bar's only window onto this is the XPC control surface
        // (ipc-contract §7.2), so the reason has to be readable *there*, not just in the
        // Supervisor's own bookkeeping.
        let serving = await runtime.controlSurface()
        let control = try XCTUnwrap(serving, "the control surface is serving after a failed migration")
        let overXpc = try await control.services()
        XCTAssertEqual(overXpc.map(\.name), [.core, .worker, .scheduler])
        XCTAssertEqual(Set(overXpc.map(\.state)), [.failed])
        XCTAssertEqual(
            Set(overXpc.compactMap(\.detail)),
            ["The database migrations did not finish (exited with status 1); no service was started. Nothing was deleted and no data was lost. Trying again continues from where it stopped."],
            "the GUI reads the reason from services()"
        )

        // And "Start services" re-runs the same preparation in this same process: the migration is
        // spawned a second time, fails the same way, and the refusal carries the same sanitised
        // reason the panel shows.
        do {
            try await control.startAll()
            XCTFail("a failing migration must gate start")
        } catch let error as SupervisorControlError {
            XCTAssertEqual(error, .refused("The database migrations did not finish (exited with status 1); no service was started. Nothing was deleted and no data was lost. Trying again continues from where it stopped."))
        }

        XCTAssertEqual(spawnLines(), [Self.migrateCommand, Self.migrateCommand], "the retry re-ran the migration and still spawned no child")

        await runtime.shutdown()
        XCTAssertFalse(FileManager.default.fileExists(atPath: socket.path), "shutdown unlinks the socket")
    }

    /// A migration that never finishes must not hang the boot: it is stopped at the bound and
    /// becomes the ordinary fail-closed path, with a reason the GUI can show. Without this, no
    /// service would ever be spawned and "Start services" would hang too.
    func testAMigrationThatNeverFinishesIsStoppedAtTheBound() async throws {
        try installRuntime(frankenphp: Self.hangingMigrationFrankenphp)
        let keychain = InMemoryKeychainStore()
        var configuration = configuration(keychain: keychain, socketLabel: "hang")
        configuration.migrationTimeout = .seconds(1)
        let runtime = SupervisorRuntime(configuration: configuration)

        let started = ContinuousClock.now
        try await runtime.boot()
        let elapsed = ContinuousClock.now - started

        XCTAssertLessThan(elapsed, .seconds(30), "the boot is bounded, not waiting out the child's 120 s sleep")

        for status in await runtime.statuses() {
            XCTAssertEqual(status.state, .failed)
            XCTAssertEqual(status.detail, "The database migrations did not finish (no result after 1 seconds); no service was started. Nothing was deleted and no data was lost. Trying again continues from where it stopped.")
        }

        XCTAssertEqual(spawnLines(), [Self.migrateCommand], "no child was spawned")

        // The sleeping child was stopped, not left orphaned: it recorded its own pid (and `exec`s
        // `sleep`, so that pid *is* the process the Supervisor signals).
        let recorded = Self.text(at: paths.logs.appending(path: "migrate.pid")).trimmingCharacters(in: .whitespacesAndNewlines)
        let pid = try XCTUnwrap(pid_t(recorded), "the one-shot recorded its pid: \(recorded.debugDescription)")
        XCTAssertEqual(kill(pid, 0), -1, "pid \(pid) is still alive after the bound")
        XCTAssertEqual(errno, ESRCH)

        await runtime.shutdown()
    }

    /// What the installer's resume path relies on: `prepare()` running a second time in the same
    /// Supervisor process — a GUI "Start services" — applies the migrations again rather than
    /// assuming the first boot did it, and succeeds because `migrate --force` is idempotent.
    func testStartServicesReRunsTheMigrationInTheSameProcess() async throws {
        try installRuntime(frankenphp: Self.dataWritingFrankenphp)
        let keychain = InMemoryKeychainStore()
        let runtime = SupervisorRuntime(configuration: configuration(keychain: keychain, socketLabel: "again"))

        try await runtime.boot()

        let spawns = paths.logs.appending(path: "spawns.txt")
        _ = await ServicesTestSupport.waitUntil { Self.lines(at: spawns).count >= 4 }
        XCTAssertEqual(spawnLines().filter { $0 == Self.migrateCommand }.count, 1, "the boot ran it once")

        let serving = await runtime.controlSurface()
        let control = try XCTUnwrap(serving)
        try await control.stopAll()
        try await control.startAll()

        let reRan = await ServicesTestSupport.waitUntil {
            Self.lines(at: spawns).filter { $0 == Self.migrateCommand }.count == 2
        }
        XCTAssertTrue(reRan, "\"Start services\" re-runs the migration: \(spawnLines())")

        // Idempotent and forward-only across the two runs: the first run's row is still there.
        let database = paths.dataRoot.appending(path: "database.sqlite")
        XCTAssertEqual(try String(contentsOf: database, encoding: .utf8), "migrated\nnothing to migrate\n")

        // And it got past the migration both times — the core was spawned after the second one.
        let started = await ServicesTestSupport.waitUntil {
            let lines = Self.lines(at: spawns)

            guard let migration = lines.lastIndex(of: Self.migrateCommand) else {
                return false
            }

            return lines[migration...].contains("run --config Caddyfile")
        }
        XCTAssertTrue(started, "the core was spawned after the second migration: \(spawnLines())")

        await runtime.shutdown()
    }

    /// A second boot over a database that is already migrated runs `migrate --force` again and
    /// succeeds — forward-only and idempotent. The row the first boot wrote is still there, which
    /// is the property a reinstall depends on (installer.md §3, threat model T-72).
    func testSecondBootOverAnAlreadyMigratedDatabaseSucceedsAndKeepsTheData() async throws {
        try installRuntime(frankenphp: Self.dataWritingFrankenphp)
        let keychain = InMemoryKeychainStore()

        let spawns = paths.logs.appending(path: "spawns.txt")
        let first = SupervisorRuntime(configuration: configuration(keychain: keychain, socketLabel: "b1"))
        try await first.boot()
        _ = await ServicesTestSupport.waitUntil { Self.lines(at: spawns).count >= 4 }
        await first.shutdown()

        let database = paths.dataRoot.appending(path: "database.sqlite")
        XCTAssertEqual(try String(contentsOf: database, encoding: .utf8), "migrated\n")

        let second = SupervisorRuntime(configuration: configuration(keychain: keychain, socketLabel: "b2"))
        try await second.boot()

        for status in await second.statuses() {
            XCTAssertNotEqual(status.detail, "The database migrations did not finish (exited with status 1); no service was started. Nothing was deleted and no data was lost. Trying again continues from where it stopped.")
        }

        // The second boot got past the migration: the core was spawned after it. The children
        // write their own records, so wait for that rather than for `boot()` to return.
        let started = await ServicesTestSupport.waitUntil {
            let lines = Self.lines(at: spawns)

            guard let migration = lines.lastIndex(of: Self.migrateCommand) else {
                return false
            }

            return lines[migration...].contains("run --config Caddyfile")
        }
        XCTAssertTrue(started, "the second boot started the core after migrating again: \(spawnLines())")

        let lines = spawnLines()
        XCTAssertEqual(lines.filter { $0 == Self.migrateCommand }.count, 2, "once per boot, not once per install: \(lines)")

        // Nothing was dropped: the first boot's line survives and the second one is appended.
        XCTAssertEqual(try String(contentsOf: database, encoding: .utf8), "migrated\nnothing to migrate\n")

        await second.shutdown()
    }

    // MARK: - Fixtures

    /// Appends its own argv to `$LEA_LOG_DIR/spawns.txt` and echoes it, then exits 0.
    private static let recordingFrankenphp = """
    #!/bin/sh
    mkdir -p "$LEA_LOG_DIR"
    echo "$@" >> "$LEA_LOG_DIR/spawns.txt"
    echo "simulated runtime: $@"

    """

    /// As above, but `migrate` fails the way a locked or unreadable database fails.
    private static let failingMigrationFrankenphp = """
    #!/bin/sh
    mkdir -p "$LEA_LOG_DIR"
    echo "$@" >> "$LEA_LOG_DIR/spawns.txt"
    if [ "$3" = "migrate" ]; then
        echo "SQLSTATE[HY000]: General error: 5 database is locked" >&2
        exit 1
    fi
    echo "simulated runtime: $@"

    """

    /// A `migrate` that never finishes. `exec` so the recorded pid is the process that sleeps —
    /// SIGTERM to the shell would otherwise leave `sleep` orphaned for two minutes.
    private static let hangingMigrationFrankenphp = """
    #!/bin/sh
    mkdir -p "$LEA_LOG_DIR"
    echo "$@" >> "$LEA_LOG_DIR/spawns.txt"
    if [ "$3" = "migrate" ]; then
        echo $$ > "$LEA_LOG_DIR/migrate.pid"
        exec sleep 120
    fi
    echo "simulated runtime: $@"

    """

    /// Stands in for a real `migrate`: writes the data file on the first run and appends on every
    /// later one, so a test can prove the first run's content survived.
    private static let dataWritingFrankenphp = """
    #!/bin/sh
    mkdir -p "$LEA_LOG_DIR" "$LEA_DATA_DIR"
    echo "$@" >> "$LEA_LOG_DIR/spawns.txt"
    if [ "$3" = "migrate" ]; then
        if [ -f "$LEA_DATA_DIR/database.sqlite" ]; then
            echo "nothing to migrate" >> "$LEA_DATA_DIR/database.sqlite"
        else
            echo "migrated" > "$LEA_DATA_DIR/database.sqlite"
        fi
    fi
    echo "simulated runtime: $@"

    """

    /// The argv line the recording `frankenphp` writes for the one-shot.
    private static let migrateCommand = "php-cli app/artisan migrate --force"

    private func spawnLines() -> [String] {
        Self.lines(at: paths.logs.appending(path: "spawns.txt"))
    }

    private static func lines(at url: URL) -> [String] {
        text(at: url).split(separator: "\n").map(String.init)
    }

    /// A file that may not exist yet reads as empty, so a `waitUntil` can poll it.
    private static func text(at url: URL) -> String {
        (try? String(contentsOf: url, encoding: .utf8)) ?? ""
    }

    private var migrateLog: URL {
        paths.logs.appending(path: "migrate.log")
    }

    /// A verifiable runtime package whose `frankenphp` is `script`, with `current` pointing at it
    /// and a checkpoint carrying the port. The mode bit is not part of the manifest, so making it
    /// executable keeps the package verifiable.
    private func installRuntime(frankenphp script: String, port: Int = 8787) throws {
        let directory = try SimulatedRuntimePackage.write(
            under: paths.runtimeDirectory(version: "0.1.0"),
            files: [
                ("frankenphp", Data(script.utf8)),
                ("Caddyfile", Data("bind 127.0.0.1\n".utf8)),
                ("php.ini", Data("opcache.jit=disable\n".utf8)),
                ("app/public/index.php", Data("<?php echo 'ok';\n".utf8)),
            ]
        )
        try FileManager.default.setAttributes([.posixPermissions: 0o755], ofItemAtPath: directory.appending(path: "frankenphp").path)
        try FileManager.default.createDirectory(at: paths.runtimeRoot, withIntermediateDirectories: true)
        try FileManager.default.createSymbolicLink(atPath: paths.currentRuntimeLink.path, withDestinationPath: "0.1.0")
        try FileManager.default.createDirectory(at: paths.installDirectory, withIntermediateDirectories: true)
        try Data("{\"schema_version\":1,\"preflight\":{\"port\":\(port)}}".utf8).write(to: paths.checkpointURL)
    }

    private func configuration(keychain: InMemoryKeychainStore, socketLabel: String) -> SupervisorConfiguration {
        var supervision = ServicesTestSupport.fastConfiguration()
        supervision.policy = RestartPolicy(delays: [.milliseconds(5)], maxRestarts: 1, window: .seconds(60))

        return SupervisorConfiguration(
            identity: SupervisorIdentity(bundlePrefix: "se.stafe.executive-ai", teamIdentifier: nil),
            paths: paths,
            keychain: keychain,
            verifier: PackageVerifier(signatureChecker: AcceptAnySignatureChecker()),
            peerVerifier: RecordingPeerVerifier(verdict: .rejected("test")),
            authenticator: ScriptedDeviceOwnerAuthenticator(succeeds: true),
            allowUnsignedXPCPeers: false,
            supervision: supervision,
            makeProbe: { _, _ in ScriptedHealthProbing() },
            liveInterval: .seconds(60),
            readyInterval: .seconds(60),
            secretsSocketOverride: ServicesTestSupport.shortSocketPath(socketLabel)
        )
    }
}

// MARK: - The broker must know the one-shot (field failure, 2026-10-13)

/// The first install on real hardware failed here, and nothing in CI could have shown it.
///
/// The migration one-shot asks the secrets broker for `APP_KEY` as it boots, exactly as the core
/// does. The broker refuses a peer the Supervisor did not spawn (`SecretsBroker`, ADR-003 D3.2)
/// and reads the set of spawned pids from `PidRegistry`. That set was fed **only** by the
/// supervisor's status observer — the three long-lived children — so the one-shot's pid was in it
/// at no point. The Supervisor's own log recorded the whole sequence in two seconds:
///
///     applying database migrations
///     secrets peer rejected
///     boot preparation failed: The database migrations did not finish (exited with status 1)
///
/// These cover the mechanism that was missing. What they cannot cover is the broker accepting the
/// peer end to end, which needs a real process tree and a real signed binary — it is on the
/// hardware list in `services.md` §10.
extension SupervisorMigrationsTests {
    /// A runnable script under the per-test root; same permissions the simulated runtime gets.
    fileprivate func writeExecutable(named name: String, body: String) throws -> URL {
        let url = root.appending(path: name)
        try Data(body.utf8).write(to: url)
        try FileManager.default.setAttributes([.posixPermissions: 0o755], ofItemAtPath: url.path)

        return url
    }

    /// The defect itself: `update` replaces the supervised set, so a one-shot sharing it would be
    /// evicted by the next status change. Two sets, one answer.
    func testAOneShotStaysAdmittedWhenTheSupervisedSetIsRepublished() {
        let registry = PidRegistry()
        registry.update([101, 102])
        registry.addTransient(900)

        XCTAssertTrue(registry.current.contains(900), "the one-shot is admitted while it runs")

        // What the supervisor's observer does on every status change.
        registry.update([101, 102, 103])

        XCTAssertTrue(
            registry.current.contains(900),
            "republishing the supervised children must not evict a one-shot that is still running"
        )
        XCTAssertEqual(registry.current, [101, 102, 103, 900])

        registry.removeTransient(900)
        XCTAssertFalse(registry.current.contains(900), "and it is gone the moment it exits")
        XCTAssertEqual(registry.current, [101, 102, 103])
    }

    /// Registering must happen before the child can act on it, and must be undone on every exit
    /// path. `onSpawn` fires inside the launch; the `defer` releases whatever it got.
    func testAChildIsAdmittedBeforeItRunsAndReleasedAfterItLeaves() async throws {
        let registry = PidRegistry()
        let handle = PidHandle()
        let script = try writeExecutable(named: "one-shot.sh", body: "#!/bin/sh\nexit 0\n")
        let admitted = LockedBox<Bool>(false)

        let child = ChildProcess(
            spec: OneShotSpec(
                logName: "probe",
                executable: script,
                arguments: [],
                workingDirectory: root,
                environment: [:]
            ),
            log: CapturingLogSink(),
            onSpawn: { pid in
                registry.addTransient(pid)
                handle.remember(pid)
                admitted.set(registry.current.contains(pid))
            }
        )

        defer { handle.release(from: registry) }

        let exit = try await child.run(timeout: .seconds(10))

        XCTAssertEqual(exit.reason, .exited(status: 0))
        XCTAssertTrue(admitted.value, "the pid is in the registry before the process can use it")

        handle.release(from: registry)
        XCTAssertTrue(registry.current.isEmpty, "and released once it has left")
    }

    /// A spawn that never happens must not leave a pid behind, and must not crash the release.
    func testAChildThatCannotStartLeavesNothingRegistered() async {
        let registry = PidRegistry()
        let handle = PidHandle()

        let child = ChildProcess(
            spec: OneShotSpec(
                logName: "missing",
                executable: root.appending(path: "does-not-exist"),
                arguments: [],
                workingDirectory: root,
                environment: [:]
            ),
            log: CapturingLogSink(),
            onSpawn: { pid in
                registry.addTransient(pid)
                handle.remember(pid)
            }
        )

        do {
            _ = try await child.run(timeout: .seconds(5))
            XCTFail("a missing executable must throw")
        } catch {
            // expected
        }

        handle.release(from: registry)
        XCTAssertTrue(registry.current.isEmpty)
    }
}

/// A `Sendable` box for a value an `@Sendable` closure writes and the test reads.
private final class LockedBox<Value: Sendable>: Sendable {
    private let lock = NSLock()
    nonisolated(unsafe) private var stored: Value

    init(_ value: Value) {
        stored = value
    }

    func set(_ value: Value) {
        lock.withLock { stored = value }
    }

    var value: Value {
        lock.withLock { stored }
    }
}
