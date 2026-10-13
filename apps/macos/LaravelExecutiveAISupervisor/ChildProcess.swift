// One child (ADR-001 D3). Wraps `Foundation.Process`: spawns with exactly the environment in the
// spec, pipes stdout and stderr into a log sink, reports the exit once, and stops with SIGTERM
// followed by SIGKILL after a grace period.
//
// Both kinds of child go through this one type: a supervised `ServiceSpec`, started with
// `start(onExit:)` and respawned by `ServiceSupervisor`, and the one-shot `OneShotSpec`, run to
// completion with `run()` before anything is supervised at all.

import Foundation

actor ChildProcess {
    let spec: any ProcessLaunch
    private let log: any LogSink
    private var process: Process?
    private var exited = false
    /// Set by `run(timeout:)`'s watchdog before it stops the process.
    private var timedOut = false

    init(spec: any ProcessLaunch, log: any LogSink) {
        self.spec = spec
        self.log = log
    }

    var pid: Int32? {
        guard let process, process.isRunning else {
            return nil
        }

        return process.processIdentifier
    }

    var isRunning: Bool {
        process?.isRunning ?? false
    }

    /// Spawns the process. `onExit` is called exactly once, from an arbitrary thread, when it
    /// leaves. Throws `ServiceExit.Reason.spawnFailed` wrapped in `ChildProcessError` when it
    /// cannot be started at all (missing executable, permissions).
    func start(onExit: @escaping @Sendable (ServiceExit) -> Void) throws {
        precondition(process == nil, "a ChildProcess starts once")

        let process = Process()
        process.executableURL = spec.executable
        process.arguments = spec.arguments
        process.currentDirectoryURL = spec.workingDirectory
        // The whole environment, not a merge: nothing from the Supervisor's own environment
        // reaches a child (ADR-003 D7 row 12).
        process.environment = spec.environment
        process.standardInput = FileHandle.nullDevice

        let stdout = Pipe()
        let stderr = Pipe()
        process.standardOutput = stdout
        process.standardError = stderr

        let sink = log
        stdout.fileHandleForReading.readabilityHandler = { handle in
            Self.forward(handle, to: sink)
        }
        stderr.fileHandleForReading.readabilityHandler = { handle in
            Self.forward(handle, to: sink)
        }

        process.terminationHandler = { finished in
            stdout.fileHandleForReading.readabilityHandler = nil
            stderr.fileHandleForReading.readabilityHandler = nil
            // Drain what is left so the last lines of a crash are not lost.
            Self.forward(stdout.fileHandleForReading, to: sink, drain: true)
            Self.forward(stderr.fileHandleForReading, to: sink, drain: true)

            let reason: ServiceExit.Reason = finished.terminationReason == .uncaughtSignal
                ? .signalled(signal: finished.terminationStatus)
                : .exited(status: finished.terminationStatus)

            onExit(ServiceExit(reason: reason, at: Date()))
        }

        do {
            try process.run()
        } catch {
            stdout.fileHandleForReading.readabilityHandler = nil
            stderr.fileHandleForReading.readabilityHandler = nil
            throw ChildProcessError.spawnFailed(spec.logName)
        }

        self.process = process
    }

    /// Spawns and returns how it left, once it has left. For the one-shots that must finish
    /// before anything is supervised (services.md §2 step 6): there is no restart budget and no
    /// probe here — the caller reads the exit status and decides. Throws like `start(onExit:)`
    /// when the process cannot be started at all.
    func run() async throws -> ServiceExit {
        try await withCheckedThrowingContinuation { continuation in
            do {
                try start { exit in
                    continuation.resume(returning: exit)
                }
            } catch {
                continuation.resume(throwing: error)
            }
        }
    }

    /// As `run()`, but bounded: after `timeout` the process is stopped (SIGTERM, then SIGKILL) and
    /// `ChildProcessError.timedOut` is thrown instead of an exit being reported.
    ///
    /// A one-shot on the boot path must never be able to wait forever. `run()` alone would hang
    /// the whole Supervisor if the child wedged: no service would ever be spawned, the GUI would
    /// show three services with no reason, and "Start services" would hang too — a dead end with
    /// nothing to diagnose. A bound turns that into the ordinary fail-closed path.
    func run(timeout: Duration) async throws -> ServiceExit {
        let watchdog = Task { [weak self] in
            try? await Task.sleep(for: timeout)

            guard !Task.isCancelled else {
                return
            }

            await self?.stopAfterTimeout()
        }

        defer { watchdog.cancel() }

        let exit = try await run()

        guard !timedOut else {
            throw ChildProcessError.timedOut(spec.logName, afterSeconds: Int(timeout.components.seconds))
        }

        return exit
    }

    /// Only a process that is still running counts as timed out: one that exited in the same
    /// instant the watchdog fired left on its own, and its status is the honest answer.
    private func stopAfterTimeout() async {
        guard let process, process.isRunning else {
            return
        }

        timedOut = true
        await terminate(grace: .seconds(5))
    }

    /// SIGTERM, then SIGKILL if the process is still there after `grace`. Returns when the
    /// process is gone. Safe to call when it already left.
    func terminate(grace: Duration) async {
        guard let process, process.isRunning else {
            return
        }

        process.terminate()

        let deadline = ContinuousClock.now + grace

        while process.isRunning, ContinuousClock.now < deadline {
            try? await Task.sleep(for: .milliseconds(50))
        }

        if process.isRunning {
            kill(process.processIdentifier, SIGKILL)
        }

        while process.isRunning {
            try? await Task.sleep(for: .milliseconds(20))
        }
    }

    private static func forward(_ handle: FileHandle, to sink: any LogSink, drain: Bool = false) {
        let data = drain ? handle.readDataToEndOfFile() : handle.availableData

        if !data.isEmpty {
            sink.append(data)
        }
    }
}

enum ChildProcessError: Error, Equatable, Sendable {
    /// The spec's `logName`, never a path.
    case spawnFailed(String)
    /// `run(timeout:)` gave up and stopped the process. Carries the spec's `logName`.
    case timedOut(String, afterSeconds: Int)
}
