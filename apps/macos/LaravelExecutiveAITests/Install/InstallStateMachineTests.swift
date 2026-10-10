// S1-05 acceptance: nine states in order, optional path only after READY, cancel in every state,
// resume from a checkpoint, rollback of an interrupted INSTALL_CORE, fail-closed PACKAGE_VERIFY
// and PREFLIGHT, READY only on `ready: true`.

import Foundation
import XCTest
@testable import LaravelExecutiveAI

@MainActor
final class InstallStateMachineTests: XCTestCase {
    private var harness: InstallHarness!

    override func setUp() async throws {
        try await MainActor.run {
            harness = try InstallHarness()
        }
    }

    override func tearDown() async throws {
        await MainActor.run {
            harness.tearDown()
        }
    }

    // MARK: - Transition table

    func testTransitionTableIsTheNineStatesInOrderPlusTheOptionalPath() {
        XCTAssertEqual(InstallState.coreSequence.map(\.rawValue), [
            "WELCOME", "PREFLIGHT", "PACKAGE_VERIFY", "INSTALL_CORE", "CREATE_OWNER",
            "SELECT_AI", "BOOT_SERVICES", "HEALTH_CHECK", "READY",
        ])
        XCTAssertEqual(InstallState.optionalPath.map(\.rawValue), [
            "CONNECT_EMAIL", "CONNECT_GITHUB", "PEEKABOO_PERMISSIONS", "REMOTE_ACCESS", "SCHEDULE_BRIEF",
        ])

        for (index, state) in InstallState.coreSequence.dropLast().enumerated() {
            XCTAssertEqual(state.allowedTransitions, [InstallState.coreSequence[index + 1]], "\(state)")
        }

        XCTAssertEqual(InstallState.ready.allowedTransitions, Set(InstallState.optionalPath))
        XCTAssertEqual(InstallState.connectEmail.allowedTransitions, [.connectGitHub, .ready])
        XCTAssertEqual(InstallState.scheduleBrief.allowedTransitions, [.ready])

        for core in InstallState.coreSequence where core != .ready {
            for optional in InstallState.optionalPath {
                XCTAssertFalse(core.canTransition(to: optional), "\(core) → \(optional) must be refused")
            }
        }

        XCTAssertFalse(InstallState.welcome.canTransition(to: .selectAI))
        XCTAssertFalse(InstallState.installCore.canTransition(to: .packageVerify))
    }

    // MARK: - Happy path

    func testHappyPathEntersExactlyTheNineStatesInOrderAndReachesReady() async throws {
        let machine = harness.makeMachine()
        await harness.runHappyPath(machine)

        XCTAssertEqual(machine.state, .ready)
        XCTAssertEqual(machine.status, .idle)
        XCTAssertTrue(machine.isReady)
        XCTAssertEqual(machine.history, InstallState.coreSequence)
        XCTAssertEqual(machine.eventLog.enteredStates, InstallState.coreSequence)

        let prompts = machine.eventLog.events.filter {
            $0.kind == .humanInteractionStart || $0.kind == .humanInteractionEnd
        }
        XCTAssertEqual(prompts.count, 6, "Welcome, owner form and AI choice, each with start and end")
        XCTAssertFalse(machine.eventLog.isWaitingForPerson)

        // Timestamps non-decreasing on the one clock.
        let times = machine.eventLog.events.map(\.at)
        XCTAssertEqual(times, times.sorted())

        // Checkpoint marks the install complete; runtime is activated, no staging left.
        let stored = try XCTUnwrap(try harness.checkpointStore.load())
        XCTAssertTrue(stored.isReady)
        XCTAssertEqual(stored.runtime?.activated, true)
        XCTAssertEqual(stored.preflight?.port, 8787)
        XCTAssertEqual(stored.aiSelection, .skip)
        XCTAssertTrue(stored.ownerCreated)

        let runtimeDirectory = harness.paths.runtimeDirectory(version: "0.1.0")
        XCTAssertTrue(FileManager.default.fileExists(atPath: runtimeDirectory.appending(path: "frankenphp").path))
        XCTAssertEqual(try FileManager.default.destinationOfSymbolicLink(atPath: harness.paths.currentRuntimeLink.path), "0.1.0")
        XCTAssertFalse(FileManager.default.fileExists(atPath: harness.paths.stagingDirectory(version: "0.1.0").path))
        XCTAssertTrue(FileManager.default.fileExists(atPath: harness.paths.databaseDirectory.path))

        XCTAssertEqual(harness.ownerCreator.drafts, [InstallHarness.owner])
        XCTAssertEqual(harness.aiApplier.applied, [.skip])
        XCTAssertEqual(harness.serviceBooter.boots.map(\.port), [8787])
        XCTAssertEqual(machine.lastHealth?.aiSetupRequired, true, "skip → AI setup required, never AI ready")
    }

    func testHappyPathEventLogRoundTripsThroughTheInstallTimer() async throws {
        guard let node = TestFiles.which("node") else {
            throw XCTSkip("node is not installed; the committed fixture is validated by the node:test suite instead")
        }

        let machine = harness.makeMachine()
        await harness.runHappyPath(machine)
        try machine.enterOptional(.connectEmail)
        try machine.finishOptional(outcome: .skipped)

        let events = harness.root.appending(path: "events.ndjson")
        try Data(machine.eventLog.ndjson.utf8).write(to: events)

        let timer = TestFiles.repositoryRoot.appending(path: "packaging/manifest/install-timer/install-timer.mjs").path
        let result = try TestFiles.run(node, [
            timer, "record", "--events", events.path, "--out", "-", "--profile", "other",
            "--runtime-cached", "no", "--chip", "x", "--memory-gb", "8", "--macos-version", "15.0",
        ])

        XCTAssertEqual(result.status, 0, result.stderr)

        // Expected numbers come from the machine's own events: READY − WELCOME, and the sum of
        // the three prompt intervals. The checkpoint saves tick the same manual clock, so the
        // values are not round, which is exactly what makes this a round-trip check.
        let logged = machine.eventLog.events
        let welcomeAt = try XCTUnwrap(logged.first { $0.kind == .stateEntry(.welcome) }?.at)
        let readyAt = try XCTUnwrap(logged.first { $0.kind == .stateEntry(.ready) }?.at)
        let starts = logged.filter { $0.kind == .humanInteractionStart }.map(\.at)
        let ends = logged.filter { $0.kind == .humanInteractionEnd }.map(\.at)
        let humanMs = zip(starts, ends).reduce(0.0) { $0 + $1.1.timeIntervalSince($1.0) } * 1000

        let record = try XCTUnwrap(try JSONSerialization.jsonObject(with: Data(result.stdout.utf8)) as? [String: Any], result.stdout)
        XCTAssertEqual(record["core_ready_ms"] as? Int, Int(readyAt.timeIntervalSince(welcomeAt) * 1000))
        XCTAssertEqual(record["human_interaction_ms"] as? Int, Int(humanMs))
        XCTAssertEqual(starts.count, 3)

        let phases = try XCTUnwrap(record["phases"] as? [String: Any])
        XCTAssertEqual(Set(phases.keys), Set(InstallState.coreSequence.dropLast().map(\.rawValue)))

        let async = try XCTUnwrap(record["async_post_core"] as? [String: Any])
        XCTAssertEqual(async["counted_in_core_ready_ms"] as? Bool, false)
        let steps = try XCTUnwrap(async["steps"] as? [String: Any])
        XCTAssertEqual((steps["CONNECT_EMAIL"] as? [String: Any])?["outcome"] as? String, "skipped")
    }

    // MARK: - Optional path

    func testOptionalPathIsUnreachableBeforeReady() async throws {
        let machine = harness.makeMachine()
        machine.start()

        XCTAssertThrowsError(try machine.enterOptional(.connectEmail)) { error in
            XCTAssertEqual(error as? InstallTransitionError, .optionalBeforeReady(.connectEmail))
        }

        await machine.proceedFromWelcome()
        XCTAssertEqual(machine.state, .createOwner)

        for step in InstallState.optionalPath {
            XCTAssertThrowsError(try machine.enterOptional(step))
        }

        XCTAssertEqual(machine.eventLog.events.filter { if case .asyncStart = $0.kind { return true }; return false }.count, 0)

        await machine.submitOwner(InstallHarness.owner)
        await machine.submitAiSelection(AiSelectionSubmission(selection: .skip))
        XCTAssertEqual(machine.state, .ready)

        try machine.enterOptional(.connectEmail)
        XCTAssertEqual(machine.state, .connectEmail)
        XCTAssertThrowsError(try machine.enterOptional(.remoteAccess), "only the next step or READY")
        try machine.finishOptional(outcome: .completed)
        XCTAssertEqual(machine.state, .ready)

        XCTAssertEqual(machine.eventLog.enteredStates.filter { $0 == .ready }.count, 1, "READY is entered once")
        XCTAssertTrue(machine.eventLog.ndjson.contains("{\"state\":\"CONNECT_EMAIL\",\"marker\":\"end\",\"outcome\":\"completed\""))
    }

    // MARK: - Cancel

    func testCancelAtWelcome() async throws {
        let machine = harness.makeMachine()
        machine.start()
        await machine.cancel()

        XCTAssertEqual(machine.status, .cancelled)
        XCTAssertEqual(machine.history, [.welcome])
        XCTAssertNil(try harness.checkpointStore.load(), "nothing completed → no checkpoint")
    }

    func testCancelAtPreflight() async throws {
        harness.dependencies.preflightProbes = HangingPreflightProbes()
        try await assertCancel(parksAt: .preflight)
    }

    func testCancelAtPackageVerify() async throws {
        harness.dependencies.packageVerifier = PackageVerifier(signatureChecker: HangingSignatureChecker())
        try await assertCancel(parksAt: .packageVerify)
    }

    func testCancelAtInstallCoreRollsBackThePartialRuntime() async throws {
        harness.dependencies.runtimeInstaller = StagingRuntimeInstaller(beforeEachFile: { index in
            if index == 2 {
                try await hangUntilCancelled()
            }
        })

        let machine = try await assertCancel(parksAt: .installCore)

        XCTAssertFalse(FileManager.default.fileExists(atPath: harness.paths.stagingDirectory(version: "0.1.0").path), "no staging directory survives")
        XCTAssertFalse(FileManager.default.fileExists(atPath: harness.paths.runtimeDirectory(version: "0.1.0").path))
        XCTAssertFalse(FileManager.default.fileExists(atPath: harness.paths.currentRuntimeLink.path))
        XCTAssertNil(machine.checkpoint.runtime)
        XCTAssertEqual(try harness.checkpointStore.load()?.lastCompletedState, .packageVerify)
    }

    func testCancelAtCreateOwner() async throws {
        let machine = harness.makeMachine()
        machine.start()
        await machine.proceedFromWelcome()
        XCTAssertEqual(machine.state, .createOwner)
        XCTAssertEqual(machine.status, .waitingForPerson)

        await machine.cancel()
        XCTAssertEqual(machine.status, .cancelled)
        XCTAssertEqual(machine.history.last, .createOwner)
        XCTAssertEqual(harness.ownerCreator.drafts, [])
    }

    func testCancelAtSelectAI() async throws {
        let machine = harness.makeMachine()
        machine.start()
        await machine.proceedFromWelcome()
        await machine.submitOwner(InstallHarness.owner)
        XCTAssertEqual(machine.state, .selectAI)

        await machine.cancel()
        XCTAssertEqual(machine.status, .cancelled)
        XCTAssertEqual(machine.history.last, .selectAI)
        XCTAssertEqual(harness.serviceBooter.boots.count, 0)
    }

    func testCancelAtBootServices() async throws {
        harness.dependencies.serviceBooter = HangingServiceBooter()
        try await assertCancel(parksAt: .bootServices)
    }

    func testCancelAtHealthCheck() async throws {
        harness.dependencies.healthProbe = HangingHealthProbe()
        let machine = try await assertCancel(parksAt: .healthCheck)
        XCTAssertFalse(machine.eventLog.enteredStates.contains(.ready))
    }

    func testCancelAfterReadyIsIgnored() async {
        let machine = harness.makeMachine()
        await harness.runHappyPath(machine)
        await machine.cancel()
        XCTAssertEqual(machine.status, .idle)
        XCTAssertEqual(machine.state, .ready)
    }

    /// Drives the machine until it is running `state`, cancels, and checks nothing later happened.
    @discardableResult
    private func assertCancel(parksAt state: InstallState, file: StaticString = #filePath, line: UInt = #line) async throws -> InstallStateMachine {
        let machine = harness.makeMachine()
        machine.start()

        let drive = Task { @MainActor in
            await machine.proceedFromWelcome()

            if machine.state == .createOwner {
                await machine.submitOwner(InstallHarness.owner)
                await machine.submitAiSelection(AiSelectionSubmission(selection: .skip))
            }
        }

        try await waitUntil(file: file, line: line) { machine.state == state && machine.status == .running }
        await machine.cancel()
        await drive.value

        XCTAssertEqual(machine.status, .cancelled, file: file, line: line)
        XCTAssertEqual(machine.state, state, file: file, line: line)
        XCTAssertEqual(machine.history.last, state, file: file, line: line)
        XCTAssertEqual(machine.eventLog.enteredStates.last, state, file: file, line: line)
        XCTAssertFalse(machine.eventLog.enteredStates.contains(.ready), file: file, line: line)

        return machine
    }

    private func waitUntil(timeout: Duration = .seconds(10), file: StaticString = #filePath, line: UInt = #line, _ condition: @MainActor () -> Bool) async throws {
        let deadline = ContinuousClock.now + timeout

        while !condition() {
            if ContinuousClock.now > deadline {
                XCTFail("condition not met in time", file: file, line: line)
                throw CancellationError()
            }

            try await Task.sleep(for: .milliseconds(10))
        }
    }

    // MARK: - Resume

    func testResumeContinuesAfterTheLastCompletedState() async throws {
        let first = harness.makeMachine()
        first.start()
        await first.proceedFromWelcome()
        await first.submitOwner(InstallHarness.owner)
        XCTAssertEqual(first.state, .selectAI)
        await first.cancel()

        let stored = try XCTUnwrap(try harness.checkpointStore.load())
        XCTAssertEqual(stored.lastCompletedState, .createOwner)
        XCTAssertEqual(stored.resumeState, .selectAI)

        let second = harness.makeMachine()
        second.start()
        XCTAssertTrue(second.canResume)
        XCTAssertEqual(second.checkpoint.runID, stored.runID, "the resumed run keeps its id and log")

        await second.proceedFromWelcome()
        XCTAssertEqual(second.state, .selectAI)
        XCTAssertEqual(second.status, .waitingForPerson)
        XCTAssertEqual(second.eventLog.enteredStates, [.welcome, .selectAI], "completed states are not re-entered")
        XCTAssertEqual(harness.ownerCreator.drafts.count, 1, "no second owner on resume")

        await second.submitAiSelection(AiSelectionSubmission(selection: .detectedLocalRuntime(.ollama)))
        XCTAssertEqual(second.state, .ready)
        XCTAssertEqual(harness.aiApplier.applied, [.detectedLocalRuntime(.ollama)])
    }

    func testResumeBeforeInstallCoreCompletedRestartsAtPreflightAndReverifies() async throws {
        var checkpoint = InstallCheckpoint(updatedAt: InstallHarness.fixtureStart)
        checkpoint.lastCompletedState = .packageVerify
        checkpoint.preflight = InstallCheckpoint.PreflightSummary(port: 8787, language: .en, existingInstall: .fresh, warnings: [], fileVaultEnabled: true)
        try harness.checkpointStore.save(checkpoint)

        let machine = harness.makeMachine()
        machine.start()
        XCTAssertTrue(machine.canResume)
        await machine.proceedFromWelcome()

        XCTAssertEqual(machine.eventLog.enteredStates, [.welcome, .preflight, .packageVerify, .installCore, .createOwner])
        XCTAssertNotNil(machine.verifiedPackage, "the package was verified again in this process")
    }

    func testFinishedInstallStartsAtReadyWithoutEventsAndWithoutTheInstaller() throws {
        var checkpoint = InstallCheckpoint(updatedAt: InstallHarness.fixtureStart)
        checkpoint.lastCompletedState = .ready
        try harness.checkpointStore.save(checkpoint)

        let machine = harness.makeMachine()
        XCTAssertTrue(machine.showsInstaller, "before start() nothing is known")
        machine.start()

        XCTAssertEqual(machine.state, .ready)
        XCTAssertTrue(machine.isReady)
        XCTAssertFalse(machine.canResume)
        XCTAssertFalse(machine.showsInstaller, "an already finished install opens the app directly")
        XCTAssertEqual(machine.eventLog.events, [])
    }

    func testDoneScreenStaysUntilOpenIsClicked() async {
        let machine = harness.makeMachine()
        machine.start()
        XCTAssertTrue(machine.showsInstaller)
        machine.acknowledgeDone()
        XCTAssertFalse(machine.doneAcknowledged, "nothing to acknowledge before READY")

        await harness.runHappyPath(machine)
        XCTAssertTrue(machine.isReady)
        XCTAssertTrue(machine.reachedReadyThisRun)
        XCTAssertTrue(machine.showsInstaller, "screen 6 is shown after READY")

        machine.acknowledgeDone()
        XCTAssertFalse(machine.showsInstaller)
    }

    // MARK: - Rollback

    func testFailureInsideInstallCoreOffersRollbackAndRetryWorksAfterwards() async throws {
        let failOnce = FailOnce()
        harness.dependencies.runtimeInstaller = StagingRuntimeInstaller(beforeEachFile: { index in
            if index == 1, await failOnce.consume() {
                throw RuntimeInstallError.copyFailed(path: "injected")
            }
        })

        let machine = harness.makeMachine()
        machine.start()
        await machine.proceedFromWelcome()

        XCTAssertEqual(machine.state, .installCore)
        guard case .failed(let failure) = machine.status, case .installCore = failure else {
            return XCTFail("expected an INSTALL_CORE failure, got \(machine.status)")
        }

        XCTAssertEqual(failure.actions, [.retry, .rollback, .cancel])
        let staging = harness.paths.stagingDirectory(version: "0.1.0")
        XCTAssertTrue(FileManager.default.fileExists(atPath: staging.path), "the partial runtime exists until rolled back")
        XCTAssertEqual(try harness.checkpointStore.load()?.hasPartialRuntime, true)

        await machine.rollback()
        XCTAssertEqual(machine.status, .rolledBack)
        XCTAssertFalse(FileManager.default.fileExists(atPath: staging.path))
        XCTAssertFalse(FileManager.default.fileExists(atPath: harness.paths.runtimeDirectory(version: "0.1.0").path))
        XCTAssertEqual(try harness.checkpointStore.load()?.hasPartialRuntime, false)

        await machine.retry()
        XCTAssertEqual(machine.state, .createOwner)
        XCTAssertEqual(machine.status, .waitingForPerson)
        XCTAssertEqual(machine.eventLog.enteredStates, [.welcome, .preflight, .packageVerify, .installCore, .installCore, .createOwner], "the retry re-enters INSTALL_CORE")
        XCTAssertTrue(FileManager.default.fileExists(atPath: harness.paths.runtimeDirectory(version: "0.1.0").appending(path: "frankenphp").path))
    }

    func testResumeAfterAnInterruptedInstallCoreRemovesThePartialRuntimeFirst() async throws {
        // Simulate a crash mid-copy: the checkpoint says staging started, the directory is there.
        let staging = harness.paths.stagingDirectory(version: "0.1.0")
        try FileManager.default.createDirectory(at: staging, withIntermediateDirectories: true)
        try Data("partial".utf8).write(to: staging.appending(path: "frankenphp"))

        var checkpoint = InstallCheckpoint(updatedAt: InstallHarness.fixtureStart)
        checkpoint.lastCompletedState = .packageVerify
        checkpoint.preflight = InstallCheckpoint.PreflightSummary(port: 8787, language: .en, existingInstall: .fresh, warnings: [], fileVaultEnabled: nil)
        checkpoint.runtime = InstallCheckpoint.RuntimeProgress(version: "0.1.0", stagingDirectoryName: staging.lastPathComponent, activated: false)
        try harness.checkpointStore.save(checkpoint)

        let machine = harness.makeMachine()
        machine.start()
        XCTAssertTrue(machine.canResume)
        await machine.proceedFromWelcome()

        XCTAssertEqual(machine.state, .createOwner)
        XCTAssertEqual(machine.checkpoint.runtime?.activated, true)
        XCTAssertFalse(FileManager.default.fileExists(atPath: staging.path))

        let installed = harness.paths.runtimeDirectory(version: "0.1.0").appending(path: "frankenphp")
        XCTAssertNotEqual(try Data(contentsOf: installed), Data("partial".utf8), "the partial file never became the runtime")
    }

    // MARK: - Fail closed

    func testPreflightBlockerStopsAtPreflightWithTheMatrixText() async throws {
        var probes = StubPreflightProbes()
        probes.arm64 = false
        harness.dependencies.preflightProbes = probes
        harness.dependencies.language = .sv

        let machine = harness.makeMachine()
        machine.start()
        await machine.proceedFromWelcome()

        XCTAssertEqual(machine.state, .preflight)
        XCTAssertEqual(machine.history, [.welcome, .preflight])

        guard case .failed(.preflightBlocked(let blockers)) = machine.status else {
            return XCTFail("expected a preflight blocker, got \(machine.status)")
        }

        XCTAssertEqual(blockers.map(\.check), [.appleSilicon])
        XCTAssertEqual(blockers[0].message.headline(machine.language), "Den här datorn har en Intel-processor. Laravel Executive AI fungerar bara på Mac-datorer med Apple Silicon (M1 eller senare).")
        XCTAssertEqual(blockers[0].message.headline(.en), "This Mac has an Intel processor. Laravel Executive AI runs only on Macs with Apple Silicon (M1 or later).")
        XCTAssertNil(machine.verifiedPackage, "nothing after PREFLIGHT ran")
        XCTAssertNil(try harness.checkpointStore.load()?.lastCompletedState)
    }

    func testTamperedPackageStopsAtPackageVerifyBeforeAnythingIsInstalled() async throws {
        try Data("tampered".utf8).write(to: harness.packageDirectory.appending(path: "Caddyfile"))

        let machine = harness.makeMachine()
        machine.start()
        await machine.proceedFromWelcome()

        XCTAssertEqual(machine.state, .packageVerify)
        guard case .failed(.packageVerification(let reason)) = machine.status else {
            return XCTFail("expected a verification failure, got \(machine.status)")
        }

        XCTAssertTrue(reason.contains("Caddyfile"), reason)
        XCTAssertFalse(FileManager.default.fileExists(atPath: harness.paths.runtimeRoot.path), "no runtime directory was created")
    }

    func testReadyIsNeverReachedWithoutReadyTrue() async throws {
        harness.dependencies.healthProbe = ScriptedHealthProbe(ready: HealthReadiness(ready: false, failingChecks: ["queue_worker"], aiState: "not_configured"))

        let machine = harness.makeMachine()
        await harness.runHappyPath(machine)

        XCTAssertEqual(machine.state, .healthCheck)
        XCTAssertEqual(machine.status, .failed(.healthNotReady(failingChecks: ["queue_worker"], attempts: 5)))
        XCTAssertFalse(machine.eventLog.enteredStates.contains(.ready))
    }

    func testUnavailableHealthProbeFailsHealthCheckInsteadOfPretending() async throws {
        harness.dependencies.healthProbe = UnavailableHealthProbe()

        let machine = harness.makeMachine()
        await harness.runHappyPath(machine)

        XCTAssertEqual(machine.state, .healthCheck)
        guard case .failed(.healthProbeUnavailable) = machine.status else {
            return XCTFail("expected healthProbeUnavailable, got \(machine.status)")
        }
    }

    func testHealthCheckPollsUntilReady() async throws {
        let probe = ScriptedHealthProbe([
            .failure(.unreachable("connection refused")),
            .success(HealthReadiness(ready: false, failingChecks: ["scheduler_selftest"], aiState: "not_configured")),
            .success(.coreReadyNoAI),
        ])
        harness.dependencies.healthProbe = probe

        let machine = harness.makeMachine()
        await harness.runHappyPath(machine)

        XCTAssertEqual(machine.state, .ready)
        XCTAssertEqual(probe.calls, 3)
        XCTAssertEqual(machine.healthAttempts, 3)
    }

    func testLoginItemApprovalIsAClickNotAPassword() async throws {
        let booter = ScriptedServiceBooter([.success(.requiresLoginItemApproval), .success(.started)])
        harness.dependencies.serviceBooter = booter

        let machine = harness.makeMachine()
        await harness.runHappyPath(machine)

        XCTAssertEqual(machine.state, .bootServices)
        XCTAssertEqual(machine.status, .failed(.loginItemApprovalRequired))
        XCTAssertEqual(InstallFailure.loginItemApprovalRequired.actions, [.openLoginItems, .retry, .cancel])
        XCTAssertTrue(machine.eventLog.isWaitingForPerson, "the approval sheet is a human prompt")

        machine.openLoginItems()
        XCTAssertEqual(booter.openedSettings, 1)

        await machine.retry()
        XCTAssertEqual(machine.state, .ready)
        XCTAssertFalse(machine.eventLog.isWaitingForPerson)
    }

    func testInvalidOwnerDraftIsRefusedWithoutLeavingTheForm() async throws {
        let machine = harness.makeMachine()
        machine.start()
        await machine.proceedFromWelcome()

        await machine.submitOwner(OwnerDraft(name: "", email: "not-an-address"))
        XCTAssertEqual(machine.state, .createOwner)
        XCTAssertEqual(machine.status, .waitingForPerson)
        XCTAssertEqual(harness.ownerCreator.drafts, [])
    }

    func testApiKeyIsHandedOnButNeverPersisted() async throws {
        let store = FileInstallCheckpointStore(url: harness.paths.checkpointURL)
        harness.dependencies.checkpointStore = store

        let machine = harness.makeMachine()
        machine.start()
        await machine.proceedFromWelcome()
        await machine.submitOwner(InstallHarness.owner)

        let secret = "lxai-test-only-key-value-0000"
        await machine.submitAiSelection(AiSelectionSubmission(selection: .ownApiKey(.openAI), apiKey: TransientSecret(secret)))

        XCTAssertEqual(machine.state, .ready)
        XCTAssertEqual(harness.aiApplier.applied, [.ownApiKey(.openAI)])

        let json = try String(contentsOf: harness.paths.checkpointURL, encoding: .utf8)
        XCTAssertFalse(json.contains(secret))
        XCTAssertTrue(json.contains("\"kind\" : \"own_api_key\""))
        XCTAssertFalse(json.contains("owner@example.se"), "the owner's address is not persisted either")
        XCTAssertFalse(machine.eventLog.ndjson.contains("example.se"))
        XCTAssertEqual(String(describing: TransientSecret(secret)!), "TransientSecret(<redacted>)")
    }

    func testOwnKeyWithoutAKeyIsRefused() async throws {
        let machine = harness.makeMachine()
        machine.start()
        await machine.proceedFromWelcome()
        await machine.submitOwner(InstallHarness.owner)

        await machine.submitAiSelection(AiSelectionSubmission(selection: .ownApiKey(.anthropic), apiKey: nil))
        XCTAssertEqual(machine.state, .selectAI)
        XCTAssertEqual(machine.status, .waitingForPerson)
    }
}

/// Returns true exactly once.
actor FailOnce {
    private var armed = true

    func consume() -> Bool {
        defer { armed = false }
        return armed
    }
}
