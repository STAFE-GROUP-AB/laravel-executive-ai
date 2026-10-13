// The test #122 did not have, and the reason it shipped a feature that could not run (#124).
//
// #122 landed with 1220 PHP tests, 333 Swift tests and seven green lanes, and the installer still
// failed on the owner screen of the first signed build with "pairing failed: the Supervisor did
// not answer". Every test handed `CREATE_OWNER` a session that was *already paired* — a world in
// which the thing that state depends on had been conjured into existence by the test itself. The
// real installer has no such world: nothing had registered the Supervisor yet, so there was no
// Supervisor to pair with and no core to answer.
//
// This file removes the ability to make that mistake. `WiredInstallWorld` is a world where every
// double refuses to work until its real prerequisite has actually happened **in this run**, and
// the causal links are the ones measured on the owner's Mac on 2026-10-13:
//
//   nothing registers the LaunchAgent          → launchd never starts the Supervisor
//   no Supervisor                              → no APP_KEY, no `K`, no broker, no migration,
//                                                no core process  (services.md §2, ADR-003 D2)
//   no Supervisor                              → `pair()` cannot answer   (ipc-contract §7.1)
//   no core serving                            → no `/health/live`, no `/ipc/v1/owner`
//   no migration                               → the core serves a database with no tables
//   no owner                                   → `auth_login` fails, so `ready` is false
//                                                (health-contract §2)
//
// Then it drives the **real** `InstallStateMachine` through the **real** `LaunchAgentServiceBooter`,
// `IpcOwnerCreator` and `IpcHealthProbe` from WELCOME to READY. Nothing is mocked out of the
// causal chain: the only fiction is that launchd, the Keychain and FrankenPHP are recordings
// instead of processes.
//
// `testTheOldOrderingFailsWithTheFieldSymptom` is the regression itself: it pins that a world
// where the Supervisor is started only at BOOT_SERVICES produces, at CREATE_OWNER, the exact
// sentence the owner read on screen.

import Foundation
import Synchronization
import XCTest
@testable import LaravelExecutiveAI

// MARK: - A world with causes in it

/// The installed state of one simulated Mac. Every flag is set by the thing that really sets it;
/// no test may set `coreServing` directly, which is the whole point.
final class WiredInstallWorld: Sendable {
    struct Facts: Sendable, Equatable {
        var launchAgentRegistered = false
        var supervisorRunning = false
        var appKeyMinted = false
        var ipcKeyMinted = false
        var databaseMigrated = false
        var coreServing = false
        var ownerExists = false
    }

    /// Set when the LaunchAgent must wait for a click in System Settings before launchd runs it.
    private let needsLoginItemApproval: Bool
    /// Set when the Supervisor is registered but the migration fails, so no child is spawned.
    private let migrationFails: Bool
    private let state: Mutex<Facts>
    private let log = Mutex<[String]>([])

    /// `ownerAlreadyExists` is the one fact a *second* launch inherits: the owner row is in the
    /// database and the database is on disk. It is seeded at construction, before anything is
    /// registered or running, precisely to keep the two halves apart — the durable half survives
    /// a relaunch, the volatile half does not, and confusing them is #124.
    init(ownerAlreadyExists: Bool = false, needsLoginItemApproval: Bool = false, migrationFails: Bool = false) {
        self.needsLoginItemApproval = needsLoginItemApproval
        self.migrationFails = migrationFails
        state = Mutex(Facts(ownerExists: ownerAlreadyExists))
    }

    var facts: Facts {
        state.withLock { $0 }
    }

    var events: [String] {
        log.withLock { $0 }
    }

    private func record(_ event: String) {
        log.withLock { $0.append(event) }
    }

    /// `SMAppService.register()`. launchd bootstraps the agent immediately, and the Supervisor's
    /// boot sequence runs: keys, broker, verification, **migration**, then the three children
    /// (services.md §2). Idempotent, like the real thing.
    func registerLaunchAgent() {
        state.withLock { facts in
            guard !facts.launchAgentRegistered else {
                return
            }

            facts.launchAgentRegistered = true
        }

        record("register")

        guard !needsLoginItemApproval else {
            // launchd will not run an unapproved login item: registered, but nothing starts.
            record("awaiting-login-item-approval")
            return
        }

        startSupervisor()
    }

    /// What launchd does once the agent is approved.
    func approveLoginItem() {
        guard state.withLock({ $0.launchAgentRegistered }) else {
            return
        }

        startSupervisor()
    }

    private func startSupervisor() {
        state.withLock { facts in
            facts.supervisorRunning = true
            facts.appKeyMinted = true
            facts.ipcKeyMinted = true
        }

        record("supervisor-up")

        guard !migrationFails else {
            record("migration-failed")
            return
        }

        state.withLock { facts in
            facts.databaseMigrated = true
            facts.coreServing = true
        }

        record("migrated")
        record("core-serving")
    }

    func createOwner() {
        state.withLock { $0.ownerExists = true }
        record("owner-created")
    }

    var launchAgentStatus: LaunchAgentStatus {
        let facts = self.facts

        if !facts.launchAgentRegistered {
            return .notRegistered
        }

        return needsLoginItemApproval && !facts.supervisorRunning ? .requiresApproval : .enabled
    }
}

/// `SMAppService`, recorded. `register()` has the consequences launchd gives it.
struct WiredRegistrar: LaunchAgentRegistering {
    let world: WiredInstallWorld

    func register() throws {
        world.registerLaunchAgent()
    }

    func unregister() async throws {}

    func status() -> LaunchAgentStatus {
        world.launchAgentStatus
    }

    func openLoginItemsSettings() {}
}

/// The Supervisor's XPC control channel. It cannot answer before the Supervisor is running, and
/// it cannot mint a pairing grant before `K` exists — that is what `K` is for.
struct WiredSupervisorControl: SupervisorControlling {
    let world: WiredInstallWorld

    private func requireRunning() throws {
        guard world.facts.supervisorRunning else {
            throw SupervisorControlError.unreachable("no Supervisor is running")
        }
    }

    func pair(client: IpcClient) async throws -> PairingGrant {
        try requireRunning()

        // `K` is the Supervisor's, minted on its first boot (services.md §2 step 3). The grant is
        // derived from the documented test key so the stubbed core can verify what the client
        // signs and sign what the client verifies.
        guard world.facts.ipcKeyMinted else {
            throw SupervisorControlError.secrets("no IPC key")
        }

        return PairingGrant.mint(key: IpcKey.fixtureKey, client: client, port: IpcTestSupport.port, now: Date())
    }

    func resetPairing() async throws -> PairingGrant {
        try await pair(client: .menubar)
    }

    func services() async throws -> [ServiceStatus] {
        try requireRunning()

        return []
    }

    func restart(service: ServiceName) async throws {
        try requireRunning()
    }

    func stopAll() async throws {
        try requireRunning()
    }

    func startAll() async throws {
        try requireRunning()
    }

    func elevate(sessionId: String, operation: String, nonce: String) async throws -> Elevation {
        try requireRunning()

        return Elevation(value: "unused", operation: operation, nonce: nonce, expiresAt: Date())
    }

    func storeProviderApiKey(provider: String, apiKey: TransientSecret) async throws {
        try requireRunning()
    }

    func removeAllSecrets(keepApplicationKey: Bool) async throws -> [KeychainItemRef] {
        try requireRunning()

        return []
    }
}

// MARK: - The tests

final class InstallDependencyOrderTests: XCTestCase {
    override func setUp() {
        super.setUp()
        IpcStubURLProtocol.reset()
    }

    override func tearDown() {
        IpcStubURLProtocol.reset()
        super.tearDown()
    }

    /// The session the client paired with, derived from the token it actually presented, so the
    /// stubbed core signs its answers with the same key the client will verify them against.
    static func signingKey(for request: URLRequest) -> Data {
        guard let header = request.value(forHTTPHeaderField: "Authorization"),
              let token = IpcToken.parse(String(header.dropFirst("Bearer ".count)))
        else {
            return IpcTestSupport.signingKey()
        }

        return IpcTestSupport.signingKey(client: token.client, sessionId: token.sessionId)
    }

    /// The core, as HTTP: it answers only what a core in this world could answer. Shared with
    /// `InstallEntryConditionTests`, which replays the same world from every resume point.
    static func installStubbedCore(_ world: WiredInstallWorld) throws {
        let created = try IpcTestSupport.successBody(data: .object(["created": .bool(true)]))
        let ownerExists = try IpcTestSupport.errorBody(code: "owner_exists", message: "An owner account already exists on this Mac.")
        let live = Data("{\"status\":\"ok\"}".utf8)
        let ready = try IpcTestSupport.healthFixtureData(.coreReadyNoAI)
        let notReady = try IpcTestSupport.healthFixtureData(.notReadySchedulerFailing)

        let internalError = try IpcTestSupport.errorBody(code: "internal")
        let notFound = try IpcTestSupport.errorBody(code: "not_found")

        IpcStubURLProtocol.install { request, _ in
            let facts = world.facts
            let path = request.url?.path ?? ""
            let key = InstallDependencyOrderTests.signingKey(for: request)

            // Nothing is listening until the Supervisor spawned the core.
            guard facts.coreServing else {
                return .failure(.cannotConnectToHost)
            }

            switch path {
            case "/health/live":
                // Outside the contract: no token, no signature (health contract §6).
                return .response(status: 200, headers: ["Content-Type": "application/json"], body: live)
            case "/ipc/v1/owner":
                guard facts.databaseMigrated else {
                    // A core serving a database with no tables: an internal error, not a refusal.
                    return IpcTestSupport.signedReply(for: request, status: 500, body: internalError, signingKey: key)
                }

                if facts.ownerExists {
                    return IpcTestSupport.signedReply(for: request, status: 409, body: ownerExists, signingKey: key)
                }

                world.createOwner()

                return IpcTestSupport.signedReply(for: request, status: 200, body: created, signingKey: key)
            case "/health/ready":
                // health-contract §2: `auth_login` ("an owner account exists") and
                // `database_migrations` are both dependencies of `ready`.
                let isReady = facts.ownerExists && facts.databaseMigrated
                let document = (try? JCS.canonicalize(isReady ? ready : notReady)) ?? ""

                return IpcTestSupport.signedReply(for: request, status: isReady ? 200 : 503, body: Data(document.utf8), signingKey: key)
            default:
                return IpcTestSupport.signedReply(for: request, status: 404, body: notFound, signingKey: key)
            }
        }
    }

    /// The installer, wired to `world` through the real booter, creator and probe. `startEarly`
    /// is the only knob: it chooses which state registers the LaunchAgent, so the same harness
    /// can run the shipped ordering and the one #122 assumed.
    @MainActor
    static func wiredHarness(
        for world: WiredInstallWorld,
        checkpoint: InstallCheckpoint? = nil,
        startSupervisorAtInstallCore startEarly: Bool = true
    ) throws -> InstallHarness {
        var harness = try InstallHarness(checkpoint: checkpoint)
        let registrar = WiredRegistrar(world: world)
        let control = WiredSupervisorControl(world: world)

        var waiting = LaunchAgentServiceBooter.Waiting()
        waiting.attempts = 3
        waiting.interval = .milliseconds(1)

        let booter = LaunchAgentServiceBooter(
            registrar: registrar,
            control: control,
            pending: PendingSecretHandoff(),
            waiting: waiting
        )

        let session = IpcSession(store: InMemorySessionStore(grant: nil), control: control, now: { Date() })

        var ownerWaiting = IpcOwnerCreator.Waiting()
        ownerWaiting.attempts = 3
        ownerWaiting.interval = .milliseconds(1)

        if startEarly {
            harness.dependencies.serviceBooter = booter
        } else {
            harness.dependencies.serviceBooter = LateServiceBooter(booter: booter)
        }

        harness.dependencies.ownerCreator = IpcOwnerCreator(
            checkpointStore: harness.checkpointStore,
            session: session,
            timeZoneIdentifier: { "Europe/Stockholm" },
            languageTag: { "sv" },
            waiting: ownerWaiting
        ) { port, session in
            IpcTestSupport.client(port: port, session: session)
        }
        harness.dependencies.healthProbe = IpcHealthProbe(checkpointStore: harness.checkpointStore, session: session) { port, session in
            IpcTestSupport.client(port: port, session: session)
        }
        harness.dependencies.healthPolicy = HealthPollingPolicy(maxAttempts: 5, interval: .zero)

        return harness
    }

    // MARK: The property

    @MainActor
    func testTheInstallerReachesReadyWithEveryDependencyProvidedBeforeItIsNeeded() async throws {
        let world = WiredInstallWorld()
        let harness = try Self.wiredHarness(for: world)
        defer { harness.tearDown() }
        try Self.installStubbedCore(world)

        let machine = harness.makeMachine()
        await harness.runHappyPath(machine)

        XCTAssertEqual(machine.state, .ready, "status: \(machine.status)")
        XCTAssertEqual(machine.status, .idle)
        XCTAssertEqual(machine.history, InstallState.coreSequence)
        XCTAssertTrue(machine.lastHealth?.ready == true)

        // Every cause fired, and in the only order that works.
        XCTAssertEqual(world.events, ["register", "supervisor-up", "migrated", "core-serving", "owner-created"])
        XCTAssertEqual(world.facts, WiredInstallWorld.Facts(
            launchAgentRegistered: true,
            supervisorRunning: true,
            appKeyMinted: true,
            ipcKeyMinted: true,
            databaseMigrated: true,
            coreServing: true,
            ownerExists: true
        ))
    }

    /// The regression. In a world where the LaunchAgent is registered only at BOOT_SERVICES — the
    /// ordering #122 believed it had — CREATE_OWNER fails, and it fails with the exact sentence
    /// the owner read on the first signed build.
    @MainActor
    func testTheOldOrderingFailsWithTheFieldSymptom() async throws {
        let world = WiredInstallWorld()
        let harness = try Self.wiredHarness(for: world, startSupervisorAtInstallCore: false)
        defer { harness.tearDown() }
        try Self.installStubbedCore(world)

        let machine = harness.makeMachine()
        machine.start()
        await machine.proceedFromWelcome()
        await machine.submitOwner(InstallHarness.owner)

        XCTAssertEqual(machine.state, .createOwner, "the install stops on the owner screen")

        guard case .failed(.ownerCreation(let reason)) = machine.status else {
            return XCTFail("expected CREATE_OWNER to fail, got \(machine.status)")
        }

        XCTAssertFalse(world.facts.supervisorRunning, "nothing had started the Supervisor yet")
        XCTAssertFalse(world.facts.coreServing)
        XCTAssertFalse(world.facts.ownerExists)
        XCTAssertEqual(world.events, [], "not one cause had fired")

        // #124 made the message accurate: the core is not up, which is what the person can act
        // on. The sentence the owner actually read on the first signed build came from the
        // pairing attempt that used to happen first, and this pins that it was this same cause.
        XCTAssertTrue(reason.contains("not answering"), reason)
        XCTAssertEqual(IpcSessionError.from(.unreachable("no Supervisor is running")), .supervisorUnreachable("the Supervisor did not answer"))
        XCTAssertEqual(
            IpcClientError.supervisorUnreachable("the Supervisor did not answer").userFacingReason,
            "pairing failed: the Supervisor did not answer",
            "the exact sentence observed on hardware on 2026-10-13"
        )
    }

    /// The Supervisor is started by INSTALL_CORE, not by CREATE_OWNER: the owner screen is only
    /// ever shown once there is something to create the owner in.
    @MainActor
    func testTheCoreIsServingBeforeTheOwnerScreenIsShown() async throws {
        let world = WiredInstallWorld()
        let harness = try Self.wiredHarness(for: world)
        defer { harness.tearDown() }
        try Self.installStubbedCore(world)

        let machine = harness.makeMachine()
        machine.start()
        await machine.proceedFromWelcome()

        XCTAssertEqual(machine.state, .createOwner)
        XCTAssertEqual(machine.status, .waitingForPerson)
        XCTAssertTrue(world.facts.supervisorRunning, "INSTALL_CORE registered the LaunchAgent")
        XCTAssertTrue(world.facts.databaseMigrated)
        XCTAssertTrue(world.facts.coreServing)
        XCTAssertFalse(world.facts.ownerExists, "the account is created by the screen, not before it")
    }

    /// A login item the person has not allowed yet: launchd runs nothing, so INSTALL_CORE stops
    /// with the click — not CREATE_OWNER with an unexplained pairing error. After the click, a
    /// retry re-enters INSTALL_CORE, which must **not** reinstall the runtime.
    @MainActor
    func testAnUnapprovedLoginItemStopsAtInstallCoreAndRetryDoesNotReinstall() async throws {
        let world = WiredInstallWorld(needsLoginItemApproval: true)
        let harness = try Self.wiredHarness(for: world)
        defer { harness.tearDown() }
        try Self.installStubbedCore(world)

        let machine = harness.makeMachine()
        machine.start()
        await machine.proceedFromWelcome()

        XCTAssertEqual(machine.state, .installCore)
        XCTAssertEqual(machine.status, .failed(.loginItemApprovalRequired))
        XCTAssertEqual(machine.status.asFailure?.actions, [.openLoginItems, .retry, .cancel], "a click, never a password")

        let installedOnce = try XCTUnwrap(try harness.checkpointStore.load()?.runtime)
        XCTAssertTrue(installedOnce.activated, "the runtime is installed and sound; only the click is missing")

        // The person clicks in System Settings, then Try again.
        world.approveLoginItem()
        await machine.retry()

        XCTAssertEqual(machine.state, .createOwner)
        XCTAssertEqual(machine.status, .waitingForPerson)
        XCTAssertEqual(world.events.filter { $0 == "register" }.count, 1, "registered once")
        XCTAssertTrue(world.facts.coreServing)
        XCTAssertEqual(machine.history.filter { $0 == .installCore }.count, 2, "re-entered, not restarted from PREFLIGHT")
    }

    /// A Supervisor that comes up but cannot migrate spawns no core (the subagent's fail-closed
    /// path). The installer must say so on the owner screen rather than pretend.
    @MainActor
    func testACoreThatNeverServesIsReportedAsUnavailableNotAsARejectedAccount() async throws {
        let world = WiredInstallWorld(migrationFails: true)
        let harness = try Self.wiredHarness(for: world)
        defer { harness.tearDown() }
        try Self.installStubbedCore(world)

        let machine = harness.makeMachine()
        machine.start()
        await machine.proceedFromWelcome()
        await machine.submitOwner(InstallHarness.owner)

        XCTAssertEqual(machine.state, .createOwner)

        guard case .failed(.ownerCreation(let reason)) = machine.status else {
            return XCTFail("expected CREATE_OWNER to fail, got \(machine.status)")
        }

        XCTAssertTrue(reason.contains("not answering"), reason)
        XCTAssertTrue(world.facts.supervisorRunning)
        XCTAssertFalse(world.facts.coreServing)
        XCTAssertFalse(world.facts.ownerExists)
    }

    /// READY is gated on an owner existing (`auth_login`, health-contract §2), so a world whose
    /// owner creation never happened can never reach it. This is the dependency HEALTH_CHECK has
    /// on CREATE_OWNER, asserted rather than assumed.
    @MainActor
    func testReadyIsUnreachableWithoutAnOwnerEvenWithEveryServiceUp() async throws {
        let world = WiredInstallWorld()
        let harness = try Self.wiredHarness(for: world)
        defer { harness.tearDown() }
        try Self.installStubbedCore(world)

        let machine = harness.makeMachine()
        machine.start()
        await machine.proceedFromWelcome()

        XCTAssertTrue(world.facts.coreServing)
        XCTAssertFalse(world.facts.ownerExists)

        // Ask the probe directly, in the state the installer is in before screen 4 is submitted.
        let readiness = try await harness.dependencies.healthProbe.readiness()

        XCTAssertFalse(readiness.ready, "no owner, so `auth_login` cannot pass and `ready` is false")
    }
}

/// The ordering #122 assumed: the LaunchAgent is registered only at BOOT_SERVICES. Used by the
/// regression test to reproduce the field failure; never wired into the app.
private struct LateServiceBooter: ServiceBooting {
    let booter: LaunchAgentServiceBooter

    func startSupervisor(runtime: InstalledRuntime, port: Int) async throws -> BootServicesOutcome {
        .started
    }

    func boot(runtime: InstalledRuntime, port: Int) async throws -> BootServicesOutcome {
        try await booter.boot(runtime: runtime, port: port)
    }

    func openLoginItemsSettings() {
        booter.openLoginItemsSettings()
    }
}

private extension InstallStatus {
    var asFailure: InstallFailure? {
        guard case .failed(let failure) = self else {
            return nil
        }

        return failure
    }
}
