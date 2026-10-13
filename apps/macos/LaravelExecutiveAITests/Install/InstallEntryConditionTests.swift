// For every state: what must be true when it is entered — on a fresh run **and on a resumed
// run** (#124).
//
// This is the assertion the two earlier failures were missing, and they were different failures
// with the same shape:
//
//   #121/#122  `CREATE_OWNER` called the core. On a fresh run nothing had started a core, because
//              the only thing that can start one — `SMAppService.register()` — ran at
//              `BOOT_SERVICES`, two states later.
//   #124       With that fixed, a *resumed* run broke instead: the checkpoint said
//              `last_completed_state: INSTALL_CORE`, so resume jumped to `CREATE_OWNER` and
//              skipped the state that starts the Supervisor. The owner's install log shows
//              exactly this — a 13:18 run that reached `INSTALL_CORE`, and a 14:15 run that went
//              `WELCOME → CREATE_OWNER` and died with "pairing failed".
//
// The rule both violate: **a checkpoint restores which states are done; it does not restore the
// processes those states started.** So "entering state N" has to mean the same thing either way,
// and that is what this file asserts, for the whole sequence, by replaying every resume point
// against the wired world of `InstallDependencyOrderTests`.
//
// The table itself is `InstallState.volatileEffects`. A new state with a volatile effect that
// does not declare itself there fails `testEveryVolatileEffectIsDeclaredAndEitherRestorableOrReEntered`.

import Foundation
import XCTest
@testable import LaravelExecutiveAI

final class InstallEntryConditionTests: XCTestCase {
    override func setUp() {
        super.setUp()
        IpcStubURLProtocol.reset()
    }

    override func tearDown() {
        IpcStubURLProtocol.reset()
        super.tearDown()
    }

    // MARK: - The property, state by state, on a resumed run

    /// Every resume point reaches READY. Each iteration is a second launch of the app against a
    /// checkpoint that says a different prefix of the sequence is done — which is the situation
    /// the owner's Mac was in, generalised over every state it could have stopped at.
    @MainActor
    func testEveryResumePointStillSatisfiesTheConditionsOfTheStatesItSkips() async throws {
        // A resume is only offered once INSTALL_CORE is complete; before that the checkpoint
        // sends the run back to PREFLIGHT (`InstallCheckpoint.resumeState`), which re-runs the
        // skipped states for real and so cannot have this defect.
        let resumable: [InstallState] = [.installCore, .createOwner, .selectAI, .bootServices, .healthCheck]

        for lastCompleted in resumable {
            let checkpoint = Self.checkpoint(lastCompleted: lastCompleted)
            let world = WiredInstallWorld(ownerAlreadyExists: checkpoint.ownerCreated)
            let harness = try InstallDependencyOrderTests.wiredHarness(for: world, checkpoint: checkpoint)
            defer { harness.tearDown() }
            try InstallDependencyOrderTests.installStubbedCore(world)

            // The volatile half starts cold: this is a *new process* on a Mac where the previous
            // run's Supervisor is long gone, however much the checkpoint says was finished.
            XCTAssertFalse(world.facts.supervisionIsUp, "resume from \(lastCompleted.rawValue) must start with nothing running")
            XCTAssertEqual(world.facts.ownerExists, checkpoint.ownerCreated, "the owner row is the durable half")

            let machine = harness.makeMachine()
            machine.start()
            await machine.resume()

            // Screens the checkpoint says are unanswered are shown again; answer them.
            await Self.answerAnyWaitingScreen(machine)

            XCTAssertEqual(machine.state, .ready, "resume from \(lastCompleted.rawValue): \(machine.status)")
            XCTAssertTrue(
                world.facts.supervisionIsUp,
                "resume from \(lastCompleted.rawValue) reached READY without re-establishing \(InstallVolatileEffect.supervisionRunning.label)"
            )
        }
    }

    /// The regression, named. A resume that skips `INSTALL_CORE` must still start the Supervisor,
    /// because the runtime being on disk is not evidence that anything is running.
    @MainActor
    func testAResumePastInstallCoreStartsTheSupervisorTheSkippedStateWasResponsibleFor() async throws {
        let world = WiredInstallWorld()
        let harness = try InstallDependencyOrderTests.wiredHarness(
            for: world,
            checkpoint: Self.checkpoint(lastCompleted: .installCore)
        )
        defer { harness.tearDown() }
        try InstallDependencyOrderTests.installStubbedCore(world)

        let machine = harness.makeMachine()
        machine.start()
        await machine.resume()

        // The owner screen is reached *and* has something to talk to. (This checkpoint stops at
        // INSTALL_CORE, so no owner exists yet: the screen really does create one.)
        XCTAssertEqual(machine.state, .createOwner)
        XCTAssertEqual(machine.status, .waitingForPerson)
        XCTAssertTrue(world.facts.launchAgentRegistered, "the skipped state's registration was re-done")
        XCTAssertTrue(world.facts.supervisorRunning)
        XCTAssertTrue(world.facts.databaseMigrated)
        XCTAssertTrue(world.facts.coreServing)

        await machine.submitOwner(InstallHarness.owner)

        // SELECT_AI is screen 5 and was never answered in the previous run, so it is shown.
        XCTAssertEqual(machine.state, .selectAI)
        XCTAssertNil(machine.status.asInstallFailure, "the owner was created, not refused")

        await machine.submitAiSelection(AiSelectionSubmission(selection: .skip))

        XCTAssertEqual(machine.state, .ready, "\(machine.status)")
        XCTAssertTrue(world.facts.ownerExists)
    }

    /// And the runtime is not installed a second time: re-establishing a volatile effect must not
    /// redo the durable work, or every resume would copy and re-hash 180 MB.
    @MainActor
    func testReestablishingDoesNotRedoTheDurableWork() async throws {
        let world = WiredInstallWorld()
        let harness = try InstallDependencyOrderTests.wiredHarness(
            for: world,
            checkpoint: Self.checkpoint(lastCompleted: .installCore)
        )
        defer { harness.tearDown() }
        try InstallDependencyOrderTests.installStubbedCore(world)

        // The checkpoint claims a runtime version that was never written to this temporary root.
        // A resume that tried to reinstall would fail on the missing package; one that only
        // re-establishes the services does not care.
        let machine = harness.makeMachine()
        machine.start()
        await machine.resume()

        XCTAssertEqual(machine.state, .createOwner)
        XCTAssertEqual(try harness.checkpointStore.load()?.runtime?.version, "0.1.0")
        XCTAssertEqual(world.events.filter { $0 == "register" }.count, 1)
    }

    /// An effect that *cannot* be restored must send the person back to the screen that produced
    /// it, not be silently skipped. The SELECT_AI key lives in memory only (ADR-003 D2), so a
    /// resume that skips a SELECT_AI whose key was never handed over re-asks.
    @MainActor
    func testAnUnrestorableEffectReEntersItsScreenInsteadOfBeingLost() async throws {
        var checkpoint = Self.checkpoint(lastCompleted: .selectAI)
        checkpoint.ownerCreated = true
        checkpoint.aiSelection = .ownApiKey(.openAI)

        let world = WiredInstallWorld(ownerAlreadyExists: true)
        let harness = try InstallDependencyOrderTests.wiredHarness(for: world, checkpoint: checkpoint)
        defer { harness.tearDown() }
        try InstallDependencyOrderTests.installStubbedCore(world)

        let machine = harness.makeMachine()
        machine.start()
        await machine.resume()

        XCTAssertEqual(machine.state, .selectAI, "the key is gone, so screen 5 is shown again")
        XCTAssertEqual(machine.status, .waitingForPerson)
        XCTAssertNil(try harness.checkpointStore.load()?.aiSelection, "the recorded choice was cleared, not honoured without its key")

        await machine.submitAiSelection(AiSelectionSubmission(selection: .skip))

        XCTAssertEqual(machine.state, .ready, "\(machine.status)")
    }

    /// The same effect, once it has actually been handed over, is not re-asked: a resume past a
    /// completed BOOT_SERVICES leaves the recorded choice alone.
    @MainActor
    func testAKeyAlreadyHandedOverIsNotReAsked() async throws {
        var checkpoint = Self.checkpoint(lastCompleted: .bootServices)
        checkpoint.ownerCreated = true
        checkpoint.aiSelection = .ownApiKey(.openAI)

        let world = WiredInstallWorld(ownerAlreadyExists: true)
        let harness = try InstallDependencyOrderTests.wiredHarness(for: world, checkpoint: checkpoint)
        defer { harness.tearDown() }
        try InstallDependencyOrderTests.installStubbedCore(world)

        let machine = harness.makeMachine()
        machine.start()
        await machine.resume()

        XCTAssertEqual(machine.state, .ready, "\(machine.status)")
        XCTAssertEqual(try harness.checkpointStore.load()?.aiSelection, .ownApiKey(.openAI))
    }

    // MARK: - The table

    /// Every volatile effect is declared by exactly the states that produce it, and every one is
    /// either restorable (re-run the producer) or handled by re-entering its screen. An effect
    /// that is neither is a silent hole, which is what #124 was.
    func testEveryVolatileEffectIsDeclaredAndEitherRestorableOrReEntered() {
        let declared = Set(InstallState.coreSequence.flatMap(\.volatileEffects))

        XCTAssertEqual(declared, Set(InstallVolatileEffect.allCases), "every effect must have a producing state")
        XCTAssertEqual(InstallState.installCore.volatileEffects, [.supervisionRunning])
        XCTAssertEqual(InstallState.selectAI.volatileEffects, [.pendingProviderKey])
        XCTAssertEqual(InstallVolatileEffect.supervisionRunning.isRestorable, true)
        XCTAssertEqual(InstallVolatileEffect.pendingProviderKey.isRestorable, false)

        for state in [InstallState.welcome, .preflight, .packageVerify, .createOwner, .bootServices, .healthCheck, .ready] {
            XCTAssertEqual(state.volatileEffects, [], "\(state.rawValue) claims a volatile effect without a re-establish path")
        }
    }

    /// The durable half, for contrast: these are the postconditions the checkpoint really does
    /// restore, and the ones a resume is right to trust.
    func testTheCheckpointRestoresOnlyTheDurableHalf() throws {
        let checkpoint = Self.checkpoint(lastCompleted: .selectAI)
        let encoded = try JSONEncoder().encode(checkpoint)
        let json = String(decoding: encoded, as: UTF8.self)

        XCTAssertTrue(json.contains("\"activated\":true") || json.contains("\"activated\" : true"))
        XCTAssertTrue(json.contains("owner_created"))
        // Nothing about processes, registrations or keys is in there, and that is correct —
        // it is why they have to be re-established instead of read back.
        for absent in ["supervisor", "launchd", "pid", "api_key", "apiKey", "frankenphp"] {
            XCTAssertFalse(json.lowercased().contains(absent.lowercased()), "the checkpoint must not claim to record \(absent)")
        }
    }

    // MARK: -

    /// Answers whichever of the two mandatory screens the resume lands on, in order. A resume
    /// from an earlier point shows both; from a later one, neither.
    @MainActor
    private static func answerAnyWaitingScreen(_ machine: InstallStateMachine) async {
        for _ in 0..<2 {
            guard machine.status == .waitingForPerson else {
                return
            }

            switch machine.state {
            case .createOwner:
                await machine.submitOwner(InstallHarness.owner)
            case .selectAI:
                await machine.submitAiSelection(AiSelectionSubmission(selection: .skip))
            default:
                return
            }
        }
    }

    /// A checkpoint of a previous run that got as far as `lastCompleted`.
    private static func checkpoint(lastCompleted: InstallState) -> InstallCheckpoint {
        var checkpoint = InstallCheckpoint(updatedAt: Date(timeIntervalSince1970: 1_791_540_000))
        checkpoint.lastCompletedState = lastCompleted
        checkpoint.preflight = InstallCheckpoint.PreflightSummary(
            port: IpcTestSupport.port,
            language: .en,
            existingInstall: .fresh,
            warnings: [],
            fileVaultEnabled: true
        )
        checkpoint.runtime = InstallCheckpoint.RuntimeProgress(version: "0.1.0", stagingDirectoryName: nil, activated: true)

        guard let index = lastCompleted.coreIndex else {
            return checkpoint
        }

        if index >= InstallState.createOwner.coreIndex! {
            checkpoint.ownerCreated = true
        }

        if index >= InstallState.selectAI.coreIndex! {
            checkpoint.aiSelection = .skip
        }

        return checkpoint
    }
}

extension InstallStatus {
    var asInstallFailure: InstallFailure? {
        guard case .failed(let failure) = self else {
            return nil
        }

        return failure
    }
}

extension WiredInstallWorld.Facts {
    /// Everything `InstallVolatileEffect.supervisionRunning` stands for.
    var supervisionIsUp: Bool {
        launchAgentRegistered && supervisorRunning && databaseMigrated && coreServing
    }
}
