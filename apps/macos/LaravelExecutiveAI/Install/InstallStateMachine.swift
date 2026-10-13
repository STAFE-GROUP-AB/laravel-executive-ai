// Installer state machine (S1-05, blueprint §12.1).
//
// Drives the nine core states in order, waits for the person on exactly three screens
// (Welcome, Create owner, Choose AI), persists a checkpoint after every completed state, and
// writes the install-measurement event log. Cancel works in every state before READY; a
// half-done INSTALL_CORE is rolled back so no partial runtime stays on disk; a stored checkpoint
// resumes a run after the app quit. READY is entered only after the health probe returned
// `ready: true` — never on a timer.
//
// Everything that could take time or fail is behind a protocol in `InstallDependencies`, so the
// machine is tested with doubles and the real runtime (S1-06), Supervisor (S1-07), IPC client
// (S1-08) and owner flow (S1-02) plug in without touching this file.

import Foundation
import Observation

enum InstallAction: Equatable, Sendable, Hashable {
    case retry
    case cancel
    case rollback
    case openLoginItems

    var label: String {
        switch self {
        case .retry: return "Try again"
        case .cancel: return "Cancel"
        case .rollback: return "Remove partial install"
        case .openLoginItems: return "Open Login Items"
        }
    }
}

enum InstallFailure: Equatable, Sendable {
    case preflightBlocked([PreflightBlocker])
    case packageVerification(String)
    case installCore(String)
    case ownerCreation(String)
    case aiSelection(String)
    case bootServices(String)
    case loginItemApprovalRequired
    case healthNotReady(failingChecks: [String], attempts: Int)
    case healthProbeUnavailable(String)
    case unexpected(String)

    var title: String {
        switch self {
        case .preflightBlocked: return "This Mac can't run Laravel Executive AI yet"
        case .packageVerification: return "The runtime package did not verify"
        case .installCore: return "Installing the runtime failed"
        case .ownerCreation: return "Creating your account failed"
        case .aiSelection: return "Saving your AI choice failed"
        case .bootServices: return "Starting the services failed"
        case .loginItemApprovalRequired: return "Allow Laravel Executive AI to run in the background"
        case .healthNotReady: return "The services are not ready yet"
        case .healthProbeUnavailable: return "The health check is not available"
        case .unexpected: return "Something went wrong"
        }
    }

    var detail: String {
        switch self {
        case .preflightBlocked(let blockers):
            return "\(blockers.count) \(blockers.count == 1 ? "check" : "checks") must be fixed before the install can continue."
        case .packageVerification(let reason):
            return "\(reason) Nothing was installed or run. Download the installer again from the official release page."
        case .installCore(let reason):
            return "\(reason) You can remove the partial install and try again."
        case .ownerCreation(let reason), .aiSelection(let reason), .bootServices(let reason), .healthProbeUnavailable(let reason), .unexpected(let reason):
            return reason
        case .loginItemApprovalRequired:
            return "macOS needs your permission for the background service. Open Login Items, turn on Laravel Executive AI, then come back and try again. No administrator password is needed."
        case .healthNotReady(let failing, let attempts):
            let list = failing.isEmpty ? "no detail" : failing.joined(separator: ", ")
            return "After \(attempts) \(attempts == 1 ? "attempt" : "attempts") the core still reports: \(list)."
        }
    }

    var actions: [InstallAction] {
        switch self {
        case .preflightBlocked, .packageVerification, .ownerCreation, .aiSelection, .bootServices, .healthNotReady, .healthProbeUnavailable, .unexpected:
            return [.retry, .cancel]
        case .installCore:
            return [.retry, .rollback, .cancel]
        case .loginItemApprovalRequired:
            return [.openLoginItems, .retry, .cancel]
        }
    }
}

enum InstallStatus: Equatable, Sendable {
    /// Nothing running: before `start()`, or READY reached.
    case idle
    case running
    case waitingForPerson
    case failed(InstallFailure)
    case cancelled
    /// A partial INSTALL_CORE was removed; the person can retry or cancel.
    case rolledBack
}

struct HealthPollingPolicy: Sendable {
    var maxAttempts: Int
    var interval: Duration

    init(maxAttempts: Int = 60, interval: Duration = .seconds(2)) {
        self.maxAttempts = maxAttempts
        self.interval = interval
    }
}

/// Internal carrier so one `catch` maps every failure to a status.
struct InstallFailed: Error {
    let failure: InstallFailure
}

@MainActor
@Observable
final class InstallStateMachine {
    private(set) var state: InstallState = .welcome
    private(set) var status: InstallStatus = .idle
    /// Every state entered through the transition table, in order, including re-entries.
    private(set) var history: [InstallState] = []
    private(set) var checkpoint: InstallCheckpoint
    private(set) var preflightReport: PreflightReport?
    private(set) var verifiedPackage: VerifiedPackage?
    private(set) var installedRuntime: InstalledRuntime?
    private(set) var coreCheckpoint: InstallCoreCheckpoint?
    private(set) var lastHealth: HealthReadiness?
    private(set) var healthAttempts = 0
    private(set) var detectedRuntimes: [LocalAiRuntime] = []
    private(set) var started = false
    /// True when READY was reached in this process (the Done screen is shown once, §12.3 screen 6).
    private(set) var reachedReadyThisRun = false
    /// The person clicked "Open" on the Done screen.
    private(set) var doneAcknowledged = false

    let dependencies: InstallDependencies
    let eventLog: InstallEventLog

    private var runningTask: Task<Void, Never>?
    private var runtimeHandle: RuntimeInstallHandle?

    init(dependencies: InstallDependencies) {
        self.dependencies = dependencies

        let stored = (try? dependencies.checkpointStore.load()) ?? nil
        let initial = stored ?? InstallCheckpoint(updatedAt: dependencies.clock.now())
        checkpoint = initial
        eventLog = InstallEventLog(
            fileURL: dependencies.eventLogURL(initial.runID),
            clock: dependencies.clock,
            timeZone: dependencies.timeZone
        )
    }

    // MARK: - Derived

    var isReady: Bool {
        state == .ready || state.isOptional
    }

    /// The window shows the installer until READY, and then the Done screen until "Open" is
    /// clicked. A launch that is already READY from the checkpoint goes straight to the app.
    var showsInstaller: Bool {
        !isReady || (reachedReadyThisRun && !doneAcknowledged)
    }

    func acknowledgeDone() {
        guard isReady else {
            return
        }

        doneAcknowledged = true
    }

    /// A previous run left a checkpoint this run can continue from.
    var canResume: Bool {
        !checkpoint.isReady && (checkpoint.lastCompletedState != nil || checkpoint.hasPartialRuntime)
    }

    var failure: InstallFailure? {
        if case .failed(let failure) = status {
            return failure
        }

        return nil
    }

    var language: PreflightLanguage {
        dependencies.language
    }

    // MARK: - Entry points for the screens

    /// Called once when the window appears. Enters WELCOME, or goes straight to READY when the
    /// checkpoint says the install finished earlier.
    func start() {
        guard !started else {
            return
        }

        started = true

        if checkpoint.isReady {
            state = .ready
            status = .idle
            return
        }

        logEntry(.welcome)
        waitForPerson()
    }

    /// The Welcome CTA. Starts a fresh run, or resumes the stored one.
    func proceedFromWelcome() async {
        guard state == .welcome, status == .waitingForPerson else {
            return
        }

        eventLog.humanInteractionEnd()

        if canResume {
            await resume()
            return
        }

        runAutomated { [self] in
            try await advance(from: .preflight)
        }

        await runningTask?.value
    }

    /// Resumes from the stored checkpoint. A partial runtime is removed first; everything before
    /// a completed INSTALL_CORE is re-run (`InstallCheckpoint.resumeState`).
    func resume() async {
        guard state == .welcome, status != .running, canResume else {
            return
        }

        eventLog.humanInteractionEnd()

        runAutomated { [self] in
            if checkpoint.hasPartialRuntime {
                try await rollbackPartialRuntime()
            }

            let target = checkpoint.resumeState
            // Position silently on the predecessor: the completed states are not re-entered,
            // and the transition table still validates the move into the resume state.
            state = InstallState.coreSequence[target.coreIndex! - 1]
            try await advance(from: target)
        }

        await runningTask?.value
    }

    /// Screen 4. Invalid drafts are refused without leaving the form.
    func submitOwner(_ draft: OwnerDraft) async {
        guard state == .createOwner, status == .waitingForPerson, draft.isValid else {
            return
        }

        eventLog.humanInteractionEnd()

        runAutomated { [self] in
            do {
                // Both hand-offs complete this state: `alreadyExisted` means a
                // data-preserving reinstall found the account still there and
                // nothing was created (ipc-contract §6.9, threat model T-72).
                // The draft is dropped either way — it is never persisted here.
                _ = try await dependencies.ownerCreator.createOwner(draft)
            } catch let error as OwnerCreationError {
                throw InstallFailed(failure: .ownerCreation(Self.describe(error)))
            } catch is CancellationError {
                throw CancellationError()
            } catch {
                throw InstallFailed(failure: .ownerCreation("The core refused the account."))
            }

            checkpoint.ownerCreated = true
            complete(.createOwner)
            try await advance(from: .selectAI)
        }

        await runningTask?.value
    }

    /// Screen 5.
    func submitAiSelection(_ submission: AiSelectionSubmission) async {
        guard state == .selectAI, status == .waitingForPerson, submission.validate() == nil else {
            return
        }

        eventLog.humanInteractionEnd()

        runAutomated { [self] in
            do {
                try await dependencies.aiSelectionApplier.apply(submission)
            } catch let error as AiSelectionError {
                throw InstallFailed(failure: .aiSelection(Self.describe(error)))
            } catch is CancellationError {
                throw CancellationError()
            } catch {
                throw InstallFailed(failure: .aiSelection("The choice could not be saved."))
            }

            checkpoint.aiSelection = submission.selection
            complete(.selectAI)
            try await advance(from: .bootServices)
        }

        await runningTask?.value
    }

    /// Re-runs the current state after a failure or a rollback. For a state that waits for the
    /// person, the form is shown again.
    func retry() async {
        switch status {
        case .failed, .rolledBack:
            break
        default:
            return
        }

        if case .failed(.loginItemApprovalRequired) = status {
            eventLog.humanInteractionEnd()
        }

        if state.waitsForPerson {
            waitForPerson()
            return
        }

        runAutomated { [self] in
            if checkpoint.hasPartialRuntime {
                try await rollbackPartialRuntime()
            }

            try await advance(from: state, reentering: true)
        }

        await runningTask?.value
    }

    /// Stops whatever is running, removes a partial runtime, and leaves the run cancelled. The
    /// checkpoint of completed states is kept so the person can resume later.
    func cancel() async {
        guard !isReady else {
            return
        }

        let task = runningTask
        task?.cancel()
        await task?.value
        runningTask = nil

        if checkpoint.hasPartialRuntime {
            try? await rollbackPartialRuntime()
        }

        if checkpoint.lastCompletedState == nil {
            try? dependencies.checkpointStore.clear()
        } else {
            persistCheckpoint()
        }

        status = .cancelled
    }

    /// Removes the partial runtime after a failed INSTALL_CORE. The run stays at INSTALL_CORE so
    /// the person can try again or cancel.
    func rollback() async {
        guard state == .installCore, failure != nil || status == .cancelled else {
            return
        }

        do {
            try await rollbackPartialRuntime()
            status = .rolledBack
        } catch let error as RuntimeInstallError {
            status = .failed(.installCore(error.userFacingReason))
        } catch {
            status = .failed(.installCore("Removing the partial runtime failed."))
        }
    }

    func openLoginItems() {
        dependencies.serviceBooter.openLoginItemsSettings()
    }

    // MARK: - Optional path (§12.1), only after READY

    func enterOptional(_ step: InstallState) throws {
        guard step.isOptional, let asyncStep = step.asyncStep else {
            throw InstallTransitionError.notAllowed(from: state, to: step)
        }

        guard isReady else {
            throw InstallTransitionError.optionalBeforeReady(step)
        }

        try transition(to: step)
        eventLog.asyncStart(asyncStep)
    }

    func finishOptional(outcome: InstallAsyncOutcome) throws {
        guard state.isOptional, let asyncStep = state.asyncStep else {
            throw InstallTransitionError.notAllowed(from: state, to: .ready)
        }

        eventLog.asyncEnd(asyncStep, outcome: outcome)
        try transition(to: .ready)
    }

    // MARK: - The core sequence

    private func advance(from start: InstallState, reentering: Bool = false) async throws {
        var next: InstallState? = start
        var first = true

        while let current = next {
            if first, reentering, current == state {
                history.append(current)
                eventLog.enter(current)
            } else {
                try transition(to: current)
                eventLog.enter(current)
            }

            first = false
            status = .running

            switch current {
            case .welcome:
                throw InstallTransitionError.notAllowed(from: state, to: .welcome)
            case .preflight:
                try await runPreflight()
            case .packageVerify:
                try await runPackageVerify()
            case .installCore:
                try await runInstallCore()
            case .createOwner:
                if !checkpoint.ownerCreated {
                    waitForPerson()
                    return
                }

                complete(.createOwner)
            case .selectAI:
                if checkpoint.aiSelection == nil {
                    detectedRuntimes = await dependencies.localAiRuntimeDetector.detect()
                    waitForPerson()
                    return
                }

                complete(.selectAI)
            case .bootServices:
                try await runBootServices()
            case .healthCheck:
                try await runHealthCheck()
            case .ready:
                complete(.ready)
                reachedReadyThisRun = true
                status = .idle
                return
            default:
                return
            }

            next = current.nextCore
        }
    }

    private func runPreflight() async throws {
        let context = PreflightContext(paths: dependencies.paths, thisVersion: dependencies.productVersion, language: dependencies.language)
        let report = await Preflight(probes: dependencies.preflightProbes, context: context).run()
        preflightReport = report
        try Task.checkCancellation()

        guard report.canContinue, let summary = report.summary else {
            throw InstallFailed(failure: .preflightBlocked(report.blockers))
        }

        checkpoint.preflight = summary
        complete(.preflight)
    }

    private func runPackageVerify() async throws {
        verifiedPackage = try await verifyPackage()
        complete(.packageVerify)
    }

    private func verifyPackage() async throws -> VerifiedPackage {
        guard let directory = dependencies.bundledRuntimeDirectory else {
            throw InstallFailed(failure: .packageVerification(PackageVerificationError.directoryMissing.userFacingReason))
        }

        do {
            return try await Self.verifyOffMainActor(dependencies.packageVerifier, directory: directory)
        } catch let error as PackageVerificationError {
            throw InstallFailed(failure: .packageVerification(error.userFacingReason))
        } catch is CancellationError {
            throw CancellationError()
        } catch {
            throw InstallFailed(failure: .packageVerification("The package could not be read."))
        }
    }

    /// Hashing a 180 MB runtime and validating its signature must not block the main actor, and
    /// the task stays cancellable: a nonisolated async function runs on the generic executor.
    private nonisolated static func verifyOffMainActor(_ verifier: PackageVerifier, directory: URL) async throws -> VerifiedPackage {
        try verifier.verify(runtimeDirectory: directory)
    }

    private func runInstallCore() async throws {
        // Verify immediately before executing anything if this process has not done so yet
        // (resume, or retry after a rollback). Never install an unverified package.
        if verifiedPackage == nil {
            verifiedPackage = try await verifyPackage()
        }

        guard let package = verifiedPackage else {
            throw InstallFailed(failure: .packageVerification(PackageVerificationError.directoryMissing.userFacingReason))
        }

        coreCheckpoint = nil
        runtimeHandle = nil

        let started: @Sendable (RuntimeInstallHandle) -> Void = { handle in
            Task { @MainActor [weak self] in
                self?.recordStagingStarted(handle)
            }
        }

        let progress: @Sendable (InstallCoreCheckpoint) -> Void = { step in
            Task { @MainActor [weak self] in
                self?.coreCheckpoint = step
            }
        }

        do {
            let runtime = try await dependencies.runtimeInstaller.install(package, into: dependencies.paths, started: started, progress: progress)
            installedRuntime = runtime
            checkpoint.runtime = InstallCheckpoint.RuntimeProgress(version: runtime.version, stagingDirectoryName: nil, activated: true)
            runtimeHandle = nil
            coreCheckpoint = .completed
            complete(.installCore)
        } catch is CancellationError {
            throw CancellationError()
        } catch let error as RuntimeInstallError {
            throw InstallFailed(failure: .installCore(error.userFacingReason))
        } catch {
            throw InstallFailed(failure: .installCore("The runtime could not be installed."))
        }
    }

    private func recordStagingStarted(_ handle: RuntimeInstallHandle) {
        runtimeHandle = handle
        checkpoint.runtime = InstallCheckpoint.RuntimeProgress(version: handle.version, stagingDirectoryName: handle.stagingDirectoryName, activated: false)
        persistCheckpoint()
    }

    private func rollbackPartialRuntime() async throws {
        guard let progress = checkpoint.runtime, !progress.activated else {
            return
        }

        let handle = runtimeHandle ?? RuntimeInstallHandle(
            version: progress.version,
            stagingDirectoryName: progress.stagingDirectoryName ?? ".staging-\(progress.version)"
        )

        try await dependencies.runtimeInstaller.rollback(handle, in: dependencies.paths)
        runtimeHandle = nil
        checkpoint.runtime = nil
        coreCheckpoint = nil
        persistCheckpoint()
    }

    private func runBootServices() async throws {
        guard let port = checkpoint.preflight?.port else {
            throw InstallFailed(failure: .bootServices("Preflight did not record a free port; run the checks again."))
        }

        let runtime: InstalledRuntime

        if let installedRuntime {
            runtime = installedRuntime
        } else if let progress = checkpoint.runtime, progress.activated {
            runtime = InstalledRuntime(version: progress.version, directory: dependencies.paths.runtimeDirectory(version: progress.version))
        } else {
            throw InstallFailed(failure: .bootServices("No installed runtime was found."))
        }

        let outcome: BootServicesOutcome

        do {
            outcome = try await dependencies.serviceBooter.boot(runtime: runtime, port: port)
        } catch let error as ServiceBootError {
            throw InstallFailed(failure: .bootServices(Self.describe(error)))
        } catch is CancellationError {
            throw CancellationError()
        } catch {
            throw InstallFailed(failure: .bootServices("The services could not be started."))
        }

        switch outcome {
        case .started:
            complete(.bootServices)
        case .requiresLoginItemApproval:
            // A sheet the person must act on: a click in System Settings, never a password.
            eventLog.humanInteractionStart()
            throw InstallFailed(failure: .loginItemApprovalRequired)
        }
    }

    private func runHealthCheck() async throws {
        let policy = dependencies.healthPolicy
        healthAttempts = 0
        var failing: [String] = []

        while healthAttempts < policy.maxAttempts {
            try Task.checkCancellation()
            healthAttempts += 1

            do {
                let readiness = try await dependencies.healthProbe.readiness()
                lastHealth = readiness

                if readiness.ready {
                    complete(.healthCheck)
                    return
                }

                failing = readiness.failingChecks
            } catch let error as HealthProbeError {
                if case .unavailable(let reason) = error {
                    throw InstallFailed(failure: .healthProbeUnavailable(reason))
                }

                failing = ["core unreachable"]
            } catch is CancellationError {
                throw CancellationError()
            } catch {
                failing = ["core unreachable"]
            }

            try await Task.sleep(for: policy.interval)
        }

        throw InstallFailed(failure: .healthNotReady(failingChecks: failing, attempts: healthAttempts))
    }

    // MARK: - Mechanics

    private func transition(to next: InstallState) throws {
        try Task.checkCancellation()

        guard state.canTransition(to: next) else {
            throw InstallTransitionError.notAllowed(from: state, to: next)
        }

        state = next
        history.append(next)
    }

    private func logEntry(_ state: InstallState) {
        history.append(state)
        eventLog.enter(state)
    }

    private func waitForPerson() {
        status = .waitingForPerson
        eventLog.humanInteractionStart()
    }

    private func complete(_ state: InstallState) {
        checkpoint.lastCompletedState = state
        persistCheckpoint()
    }

    private func persistCheckpoint() {
        checkpoint.updatedAt = dependencies.clock.now()
        try? dependencies.checkpointStore.save(checkpoint)
    }

    private func runAutomated(_ work: @escaping @MainActor () async throws -> Void) {
        status = .running
        runningTask = Task { @MainActor [weak self] in
            do {
                try await work()
            } catch is CancellationError {
                // cancel() sets the status once rollback is done.
            } catch let failed as InstallFailed {
                self?.status = .failed(failed.failure)
            } catch let error as InstallTransitionError {
                self?.status = .failed(.unexpected("Transition refused: \(error)."))
            } catch {
                self?.status = .failed(.unexpected("An unexpected error stopped the install."))
            }
        }
    }

    private static func describe(_ error: OwnerCreationError) -> String {
        switch error {
        case .unavailable(let reason), .rejected(let reason): return reason
        }
    }

    private static func describe(_ error: AiSelectionError) -> String {
        switch error {
        case .apiKeyMissing: return "Enter the API key, or choose another option."
        case .unavailable(let reason), .rejected(let reason): return reason
        }
    }

    private static func describe(_ error: ServiceBootError) -> String {
        switch error {
        case .unavailable(let reason), .failed(let reason): return reason
        }
    }
}
