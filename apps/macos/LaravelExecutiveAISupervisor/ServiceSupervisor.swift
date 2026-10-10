// The inner supervision loop (ADR-001 D3; docs/architecture/services.md §3–§4).
//
// launchd keeps the Supervisor alive; the Supervisor keeps the three PHP children alive — with a
// bound. A child that exits is respawned after exponential backoff; the sixth exit inside the
// window marks it `failed` and nothing is retried until a person starts it again. State is
// driven by the health contract: the core by `/health/live`, the worker and the scheduler by
// their `/health/ready` checks. A process that is alive but unhealthy is `degraded`, and after
// repeated failures it is stopped and respawned through the same bounded path.

import Foundation

actor ServiceSupervisor {
    struct Configuration: Sendable {
        var policy: RestartPolicy = .adr001
        /// SIGTERM → SIGKILL grace when stopping a child.
        var gracePeriod: Duration = .seconds(10)
        /// Consecutive `/health/live` failures before the core is stopped and respawned.
        var liveFailuresBeforeRestart = 3
        /// Consecutive `fail` results of a service's ready check before it is respawned.
        var readyFailuresBeforeRestart = 3
        var now: @Sendable () -> Date = { Date() }
        var sleep: @Sendable (Duration) async throws -> Void = { try await Task.sleep(for: $0) }
        var makeLog: @Sendable (ServiceName) -> any LogSink = { _ in DiscardingLogSink() }

        init() {}
    }

    private struct Record {
        let spec: ServiceSpec
        var child: ChildProcess?
        /// The pid read once after a successful spawn; `ChildProcess.pid` is actor-isolated.
        var cachedPid: Int32?
        var state: ServiceState = .stopped
        var since: Date
        var detail: String?
        var budget = RestartBudget()
        var lastExit: String?
        var consecutiveProbeFailures = 0
        var wantsRunning = false
        var generation = 0
        var pendingRestart: Task<Void, Never>?
        var pendingUnhealthyReason: String?
    }

    private var records: [ServiceName: Record] = [:]
    private let order: [ServiceName]
    private let configuration: Configuration
    private var observers: [@Sendable ([ServiceStatus]) -> Void] = []

    init(specs: [ServiceSpec], configuration: Configuration = Configuration()) {
        self.configuration = configuration
        order = specs.map(\.name)

        for spec in specs {
            records[spec.name] = Record(spec: spec, since: configuration.now())
        }
    }

    // MARK: - Control (ipc-contract §7.2)

    func startAll() async {
        for name in order {
            await start(name)
        }
    }

    func stopAll() async {
        for name in order.reversed() {
            await stop(name)
        }
    }

    /// A person's (or the boot's) explicit start: clears `failed`, resets the restart budget.
    func start(_ name: ServiceName) async {
        guard var record = records[name] else {
            return
        }

        record.pendingRestart?.cancel()
        record.pendingRestart = nil
        record.wantsRunning = true
        record.budget.reset()
        record.consecutiveProbeFailures = 0
        record.pendingUnhealthyReason = nil
        records[name] = record

        if record.child == nil {
            await spawn(name)
        }
    }

    func stop(_ name: ServiceName) async {
        guard var record = records[name] else {
            return
        }

        record.pendingRestart?.cancel()
        record.pendingRestart = nil
        record.wantsRunning = false
        // The exit this terminate causes belongs to an old generation and is ignored.
        record.generation += 1
        let child = record.child
        record.child = nil
        records[name] = record

        await child?.terminate(grace: configuration.gracePeriod)

        guard var current = records[name] else {
            return
        }

        current.state = .stopped
        current.since = configuration.now()
        current.detail = nil
        current.lastExit = child == nil ? current.lastExit : "stopped on request"
        records[name] = current
        notify()
    }

    func restart(_ name: ServiceName) async {
        await stop(name)
        await start(name)
    }

    /// Boot-time verification refused to spawn anything (ADR-001 D3 "fail closed"): every
    /// service is `failed` with the reason, visible through `services()`.
    func markAllFailed(reason: String) {
        for name in order {
            guard var record = records[name] else {
                continue
            }

            record.pendingRestart?.cancel()
            record.pendingRestart = nil
            record.wantsRunning = false
            record.state = .failed
            record.since = configuration.now()
            record.detail = reason
            records[name] = record
        }

        notify()
    }

    // MARK: - Observation

    func statuses() -> [ServiceStatus] {
        order.compactMap { name in
            guard let record = records[name] else {
                return nil
            }

            return ServiceStatus(
                name: name,
                state: record.state,
                pid: record.child == nil ? nil : record.cachedPid,
                restartsInWindow: record.budget.count(now: configuration.now(), policy: configuration.policy),
                lastExitReason: record.lastExit,
                since: record.since,
                detail: record.detail
            )
        }
    }

    /// Process ids of the children currently alive — the broker's peer check (ADR-003 D3.2).
    func livePids() async -> Set<pid_t> {
        var pids = Set<pid_t>()

        for name in order {
            if let child = records[name]?.child, let pid = await child.pid {
                pids.insert(pid)
            }
        }

        return pids
    }

    func addObserver(_ observer: @escaping @Sendable ([ServiceStatus]) -> Void) {
        observers.append(observer)
        observer(statuses())
    }

    // MARK: - Health signals (health-contract.md)

    /// `GET /health/live` result for the core. Three consecutive failures while the process is
    /// alive stop it and route through the bounded restart path.
    func observeLive(ok: Bool) async {
        await observe(.core, ok: ok ? .ok : .fail, threshold: configuration.liveFailuresBeforeRestart, signal: "/health/live")
    }

    /// The `checks[]` of `GET /health/ready`, keyed by name. Only `queue_worker` and
    /// `scheduler_selftest` are read (ipc-contract §6.5: `ready` as a whole never restarts
    /// anything — `menubar_integration` failing after "Quit UI" is not a worker problem).
    func observeReady(checks: [String: HealthCheckStatus]) async {
        for name in order {
            guard let checkName = name.readyCheckName, let status = checks[checkName] else {
                continue
            }

            await observe(name, ok: status, threshold: configuration.readyFailuresBeforeRestart, signal: checkName)
        }
    }

    private func observe(_ name: ServiceName, ok status: HealthCheckStatus, threshold: Int, signal: String) async {
        guard var record = records[name], record.wantsRunning, let child = record.child else {
            return
        }

        switch status {
        case .ok:
            record.consecutiveProbeFailures = 0

            if record.state == .starting || record.state == .degraded {
                record.state = .healthy
                record.since = configuration.now()
                record.detail = nil
            }

            records[name] = record
            notify()

        case .skip:
            // A dependency failed (for example migrations); restarting this process cannot fix it.
            if record.state != .degraded {
                record.state = .degraded
                record.since = configuration.now()
            }

            record.detail = "\(signal) skipped: a check it depends on failed"
            records[name] = record
            notify()

        case .fail:
            record.consecutiveProbeFailures += 1

            if record.consecutiveProbeFailures >= threshold {
                let reason = "\(signal) failed \(record.consecutiveProbeFailures) times in a row"
                record.pendingUnhealthyReason = reason
                record.detail = reason
                records[name] = record
                notify()
                // The exit this causes carries the current generation, so `childExited`
                // treats it like a crash: backoff, budget, respawn.
                await child.terminate(grace: configuration.gracePeriod)
                return
            }

            if record.state != .degraded {
                record.state = .degraded
                record.since = configuration.now()
            }

            record.detail = "\(signal) failed (\(record.consecutiveProbeFailures) of \(threshold))"
            records[name] = record
            notify()
        }
    }

    // MARK: - Spawning and exits

    private func spawn(_ name: ServiceName) async {
        guard var record = records[name], record.wantsRunning else {
            return
        }

        record.generation += 1
        let generation = record.generation
        let child = ChildProcess(spec: record.spec, log: configuration.makeLog(name))
        record.child = child
        record.state = .starting
        record.since = configuration.now()
        record.consecutiveProbeFailures = 0
        record.pendingUnhealthyReason = nil
        records[name] = record
        notify()

        do {
            try await child.start { [weak self] exit in
                guard let self else {
                    return
                }

                Task {
                    await self.childExited(name, generation: generation, exit: exit)
                }
            }

            // Re-read the record after the suspension: a fast-exiting child may already have
            // gone through `childExited` while `pid` was awaited, and writing back a copy taken
            // before the await would resurrect the dead child and swallow its scheduled restart.
            let pid = await child.pid

            if var started = records[name], started.generation == generation, started.child != nil {
                started.cachedPid = pid
                records[name] = started
                notify()
            }
        } catch {
            await childExited(name, generation: generation, exit: ServiceExit(reason: .spawnFailed, at: configuration.now()))
        }
    }

    private func childExited(_ name: ServiceName, generation: Int, exit: ServiceExit) async {
        guard var record = records[name], record.generation == generation else {
            return
        }

        let now = configuration.now()
        record.child = nil
        record.cachedPid = nil

        if let reason = record.pendingUnhealthyReason {
            record.lastExit = ServiceExit(reason: .unhealthy(detail: reason), at: exit.at).description
            record.pendingUnhealthyReason = nil
        } else {
            record.lastExit = exit.description
        }

        guard record.wantsRunning else {
            record.state = .stopped
            record.since = now
            records[name] = record
            notify()
            return
        }

        record.budget.prune(now: now, policy: configuration.policy)

        if record.budget.isExhausted(now: now, policy: configuration.policy) {
            record.state = .failed
            record.since = now
            record.wantsRunning = false
            record.detail = "restarted \(configuration.policy.maxRestarts) times within \(Int(configuration.policy.windowSeconds / 60)) minutes; not retrying until you start it again"
            records[name] = record
            notify()
            return
        }

        let attempt = record.budget.count(now: now, policy: configuration.policy) + 1
        let delay = configuration.policy.delay(forAttempt: attempt)
        record.budget.record(now: now)
        record.state = .starting
        record.since = now
        record.detail = "restart \(attempt) of \(configuration.policy.maxRestarts) in \(Self.describe(delay)) after it \(record.lastExit ?? "exited")"

        let sleep = configuration.sleep
        record.pendingRestart = Task { [weak self] in
            try? await sleep(delay)

            guard !Task.isCancelled, let self else {
                return
            }

            await self.respawnIfWanted(name, generation: generation)
        }

        records[name] = record
        notify()
    }

    private func respawnIfWanted(_ name: ServiceName, generation: Int) async {
        guard let record = records[name], record.generation == generation, record.wantsRunning, record.child == nil else {
            return
        }

        await spawn(name)
    }

    private func notify() {
        let snapshot = statuses()

        for observer in observers {
            observer(snapshot)
        }
    }

    private static func describe(_ duration: Duration) -> String {
        let seconds = duration.components.seconds
        let millis = duration.components.attoseconds / 1_000_000_000_000_000

        if seconds == 0 {
            return "\(millis) ms"
        }

        return millis == 0 ? "\(seconds) s" : "\(seconds).\(millis / 100) s"
    }
}
