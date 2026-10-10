// S1-07: crash restart with a bound, health-driven states, clean stop (ADR-001 D3,
// services.md §3–§4). Children are real `/bin/sh` processes.

import Foundation
import XCTest
@testable import LaravelExecutiveAI

final class ServiceSupervisorTests: XCTestCase {
    private func status(_ supervisor: ServiceSupervisor, _ name: ServiceName) async throws -> ServiceStatus {
        let status = await ServicesTestSupport.status(supervisor, name)
        return try XCTUnwrap(status)
    }

    private func waitForPid(_ supervisor: ServiceSupervisor, _ name: ServiceName, otherThan previous: Int32? = nil) async throws -> Int32 {
        let appeared = await ServicesTestSupport.waitUntil {
            guard let pid = await ServicesTestSupport.status(supervisor, name)?.pid else {
                return false
            }

            return pid != previous
        }
        XCTAssertTrue(appeared, "\(name) never got a pid other than \(previous.map(String.init) ?? "nil")")

        let pid = await ServicesTestSupport.status(supervisor, name)?.pid
        return try XCTUnwrap(pid)
    }

    func testKilledChildIsRestartedWithANewPid() async throws {
        let supervisor = ServiceSupervisor(
            specs: [ServicesTestSupport.shellSpec(.core, "sleep 30")],
            configuration: ServicesTestSupport.fastConfiguration()
        )

        await supervisor.start(.core)
        let firstPid = try await waitForPid(supervisor, .core)
        let starting = try await status(supervisor, .core)
        XCTAssertEqual(starting.state, .starting)

        kill(firstPid, SIGKILL)

        let secondPid = try await waitForPid(supervisor, .core, otherThan: firstPid)
        XCTAssertNotEqual(secondPid, firstPid, "a killed child is respawned")

        let restarted = try await status(supervisor, .core)
        XCTAssertEqual(restarted.restartsInWindow, 1)
        XCTAssertEqual(restarted.lastExitReason, "terminated by signal 9")
        XCTAssertEqual(restarted.state, .starting)

        await supervisor.stopAll()
        let stopped = try await status(supervisor, .core)
        XCTAssertEqual(stopped.state, .stopped)
        XCTAssertNil(stopped.pid)
    }

    func testExceedingTheRestartBoundSurfacesFailed() async throws {
        let policy = RestartPolicy(delays: [.milliseconds(5)], maxRestarts: 3, window: .seconds(60))
        let supervisor = ServiceSupervisor(
            specs: [ServicesTestSupport.shellSpec(.worker, "exit 3")],
            configuration: ServicesTestSupport.fastConfiguration(policy: policy)
        )

        await supervisor.start(.worker)

        let failed = await ServicesTestSupport.waitUntil { await ServicesTestSupport.status(supervisor, .worker)?.state == .failed }
        XCTAssertTrue(failed, "the fourth exit inside the window is the end")

        let status = try await status(supervisor, .worker)
        XCTAssertEqual(status.restartsInWindow, 3)
        XCTAssertEqual(status.lastExitReason, "exited with status 3")
        XCTAssertEqual(status.detail, "restarted 3 times within 1 minutes; not retrying until you start it again")
        XCTAssertNil(status.pid)

        // Nothing moves on its own after `failed`.
        try await Task.sleep(for: .milliseconds(100))
        let later = try await self.status(supervisor, .worker)
        XCTAssertEqual(later.state, .failed)
        XCTAssertEqual(later.restartsInWindow, 3)

        // A person's start resets the budget and tries again: the child still exits at once, so
        // the only state that can be sampled reliably is the next `failed`; reaching it again
        // proves the budget was reset to zero and spent anew (3, not 6).
        await supervisor.start(.worker)
        let startedAgain = await ServicesTestSupport.waitUntil {
            let status = await ServicesTestSupport.status(supervisor, .worker)
            return status?.state != .failed || (status?.restartsInWindow ?? 0) > 0
        }
        XCTAssertTrue(startedAgain, "start leaves failed")

        let failedAgain = await ServicesTestSupport.waitUntil { await ServicesTestSupport.status(supervisor, .worker)?.state == .failed }
        XCTAssertTrue(failedAgain)
        let afterRetry = try await self.status(supervisor, .worker)
        XCTAssertEqual(afterRetry.restartsInWindow, 3, "the budget was reset by start, not accumulated")

        await supervisor.stopAll()
    }

    func testSpawnFailureCountsAgainstTheBound() async throws {
        let policy = RestartPolicy(delays: [.milliseconds(5)], maxRestarts: 2, window: .seconds(60))
        let supervisor = ServiceSupervisor(specs: [ServicesTestSupport.missingSpec(.scheduler)], configuration: ServicesTestSupport.fastConfiguration(policy: policy))

        await supervisor.start(.scheduler)

        let failed = await ServicesTestSupport.waitUntil { await ServicesTestSupport.status(supervisor, .scheduler)?.state == .failed }
        XCTAssertTrue(failed)
        let status = try await status(supervisor, .scheduler)
        XCTAssertEqual(status.lastExitReason, "could not be started")
    }

    func testLivenessDrivesTheCoreState() async throws {
        let supervisor = ServiceSupervisor(specs: [ServicesTestSupport.shellSpec(.core, "sleep 30")], configuration: ServicesTestSupport.fastConfiguration())

        await supervisor.start(.core)
        let firstPid = try await waitForPid(supervisor, .core)

        await supervisor.observeLive(ok: true)
        var status = try await status(supervisor, .core)
        XCTAssertEqual(status.state, .healthy)

        await supervisor.observeLive(ok: false)
        status = try await self.status(supervisor, .core)
        XCTAssertEqual(status.state, .degraded)
        XCTAssertEqual(status.detail, "/health/live failed (1 of 3)")

        await supervisor.observeLive(ok: true)
        status = try await self.status(supervisor, .core)
        XCTAssertEqual(status.state, .healthy, "one good probe clears degraded")

        await supervisor.observeLive(ok: false)
        await supervisor.observeLive(ok: false)
        await supervisor.observeLive(ok: false)

        let secondPid = try await waitForPid(supervisor, .core, otherThan: firstPid)
        XCTAssertNotEqual(secondPid, firstPid, "three consecutive liveness failures stop and respawn the core")

        status = try await self.status(supervisor, .core)
        XCTAssertEqual(status.state, .starting)
        XCTAssertEqual(status.lastExitReason, "stopped by the Supervisor: /health/live failed 3 times in a row")
        XCTAssertEqual(status.restartsInWindow, 1)

        await supervisor.stopAll()
    }

    func testReadyChecksDriveWorkerAndSchedulerWithoutTouchingTheCore() async throws {
        let supervisor = ServiceSupervisor(
            specs: [
                ServicesTestSupport.shellSpec(.core, "sleep 30"),
                ServicesTestSupport.shellSpec(.worker, "sleep 30"),
                ServicesTestSupport.shellSpec(.scheduler, "sleep 30"),
            ],
            configuration: ServicesTestSupport.fastConfiguration()
        )

        await supervisor.startAll()
        let allUp = await ServicesTestSupport.waitUntil { await supervisor.statuses().allSatisfy { $0.pid != nil } }
        XCTAssertTrue(allUp)

        await supervisor.observeReady(checks: ["queue_worker": .ok, "scheduler_selftest": .fail, "menubar_integration": .fail, "http": .ok])

        var worker = try await status(supervisor, .worker)
        var scheduler = try await status(supervisor, .scheduler)
        let core = try await status(supervisor, .core)
        XCTAssertEqual(worker.state, .healthy)
        XCTAssertEqual(scheduler.state, .degraded)
        XCTAssertEqual(core.state, .starting, "ready never moves the core (ipc-contract §6.5)")

        await supervisor.observeReady(checks: ["queue_worker": .skip, "scheduler_selftest": .ok])
        worker = try await status(supervisor, .worker)
        scheduler = try await status(supervisor, .scheduler)
        XCTAssertEqual(worker.state, .degraded)
        XCTAssertEqual(worker.detail, "queue_worker skipped: a check it depends on failed")
        XCTAssertEqual(scheduler.state, .healthy)

        let workerPid = try XCTUnwrap(worker.pid)

        // `skip` never restarts; three `fail`s do.
        await supervisor.observeReady(checks: ["queue_worker": .skip])
        await supervisor.observeReady(checks: ["queue_worker": .skip])
        worker = try await status(supervisor, .worker)
        XCTAssertEqual(worker.pid, workerPid)

        for _ in 0..<3 {
            await supervisor.observeReady(checks: ["queue_worker": .fail])
        }

        let respawnedPid = try await waitForPid(supervisor, .worker, otherThan: workerPid)
        XCTAssertNotEqual(respawnedPid, workerPid)
        worker = try await status(supervisor, .worker)
        XCTAssertEqual(worker.lastExitReason, "stopped by the Supervisor: queue_worker failed 3 times in a row")

        await supervisor.stopAll()
        let statuses = await supervisor.statuses()
        XCTAssertTrue(statuses.allSatisfy { $0.state == .stopped })
    }

    func testHealthMonitorFeedsTheSupervisor() async throws {
        let supervisor = ServiceSupervisor(
            specs: [ServicesTestSupport.shellSpec(.core, "sleep 30"), ServicesTestSupport.shellSpec(.worker, "sleep 30")],
            configuration: ServicesTestSupport.fastConfiguration()
        )
        await supervisor.startAll()
        _ = await ServicesTestSupport.waitUntil { await supervisor.statuses().allSatisfy { $0.pid != nil } }

        let probe = ScriptedHealthProbing(live: [true], ready: [.success(ReadyProbeResult(ready: false, checks: ["queue_worker": .fail]))])
        let monitor = ServiceHealthMonitor(probe: probe, supervisor: supervisor, liveInterval: .milliseconds(20), readyInterval: .milliseconds(20))
        let running = Task { await monitor.run() }

        let observed = await ServicesTestSupport.waitUntil {
            let core = await ServicesTestSupport.status(supervisor, .core)?.state
            let worker = await ServicesTestSupport.status(supervisor, .worker)?.state

            return core == .healthy && worker == .degraded
        }
        XCTAssertTrue(observed)

        running.cancel()
        await supervisor.stopAll()
    }

    func testStopIgnoresTheExitItCausesAndMarkAllFailedIsVisible() async throws {
        let supervisor = ServiceSupervisor(specs: [ServicesTestSupport.shellSpec(.core, "sleep 30")], configuration: ServicesTestSupport.fastConfiguration())
        await supervisor.start(.core)
        _ = try await waitForPid(supervisor, .core)

        await supervisor.stop(.core)
        try await Task.sleep(for: .milliseconds(100))

        let stopped = try await status(supervisor, .core)
        XCTAssertEqual(stopped.state, .stopped)
        XCTAssertEqual(stopped.restartsInWindow, 0, "an exit we caused is not a crash")
        XCTAssertEqual(stopped.lastExitReason, "stopped on request")

        await supervisor.markAllFailed(reason: "The installed runtime failed verification (x).")
        let failed = try await status(supervisor, .core)
        XCTAssertEqual(failed.state, .failed)
        XCTAssertEqual(failed.detail, "The installed runtime failed verification (x).")
    }

    func testChildOutputIsCapturedAndRotated() async throws {
        let directory = FileManager.default.temporaryDirectory.appending(path: "lxai-log-\(UUID().uuidString)")
        defer { try? FileManager.default.removeItem(at: directory) }

        let log = RotatingLogFile(url: directory.appending(path: "core.log"), maxBytes: 64, keep: 2)
        let child = ChildProcess(spec: ServicesTestSupport.shellSpec(.core, "echo hello-out; echo hello-err 1>&2"), log: log)
        let exited = expectation(description: "exit")

        try await child.start { exit in
            XCTAssertEqual(exit.reason, .exited(status: 0))
            exited.fulfill()
        }

        await fulfillment(of: [exited], timeout: 5)
        try await Task.sleep(for: .milliseconds(50))

        let text = try String(contentsOf: directory.appending(path: "core.log"), encoding: .utf8)
        XCTAssertTrue(text.contains("hello-out"))
        XCTAssertTrue(text.contains("hello-err"))

        for _ in 0..<6 {
            log.append(Data(repeating: UInt8(ascii: "x"), count: 30))
        }
        log.close()

        XCTAssertTrue(FileManager.default.fileExists(atPath: directory.appending(path: "core.log.1").path))
        XCTAssertTrue(FileManager.default.fileExists(atPath: directory.appending(path: "core.log.2").path))
        XCTAssertFalse(FileManager.default.fileExists(atPath: directory.appending(path: "core.log.3").path), "keep bounds the rotation")

        let attributes = try FileManager.default.attributesOfItem(atPath: directory.appending(path: "core.log").path)
        XCTAssertEqual((attributes[.posixPermissions] as? NSNumber)?.intValue, 0o600)
    }

    func testRestartPolicyAndBudget() {
        let policy = RestartPolicy.adr001
        XCTAssertEqual(policy.delay(forAttempt: 1), .seconds(1))
        XCTAssertEqual(policy.delay(forAttempt: 3), .seconds(4))
        XCTAssertEqual(policy.delay(forAttempt: 6), .seconds(30))
        XCTAssertEqual(policy.delay(forAttempt: 99), .seconds(30))
        XCTAssertEqual(policy.maxRestarts, 5)
        XCTAssertEqual(policy.windowSeconds, 600)

        let start = Date(timeIntervalSince1970: 1_000_000)
        var budget = RestartBudget()

        for offset in 0..<5 {
            XCTAssertFalse(budget.isExhausted(now: start.addingTimeInterval(TimeInterval(offset)), policy: policy))
            budget.record(now: start.addingTimeInterval(TimeInterval(offset)))
        }

        XCTAssertTrue(budget.isExhausted(now: start.addingTimeInterval(5), policy: policy))
        XCTAssertEqual(budget.count(now: start.addingTimeInterval(5), policy: policy), 5)

        // 601 s later the restarts at +0 (601 s old) and +1 (exactly 600 s old, on the boundary)
        // have left the window; three remain.
        budget.prune(now: start.addingTimeInterval(601), policy: policy)
        XCTAssertEqual(budget.count(now: start.addingTimeInterval(601), policy: policy), 3, "the oldest restarts left the window")
        XCTAssertFalse(budget.isExhausted(now: start.addingTimeInterval(601), policy: policy))
    }
}
