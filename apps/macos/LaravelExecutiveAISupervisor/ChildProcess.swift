// One supervised child (ADR-001 D3). Wraps `Foundation.Process`: spawns with exactly the
// environment in the spec, pipes stdout and stderr into a log sink, reports the exit once, and
// stops with SIGTERM followed by SIGKILL after a grace period.

import Foundation

actor ChildProcess {
    let spec: ServiceSpec
    private let log: any LogSink
    private var process: Process?
    private var exited = false

    init(spec: ServiceSpec, log: any LogSink) {
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
            throw ChildProcessError.spawnFailed(spec.name)
        }

        self.process = process
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
    case spawnFailed(ServiceName)
}
