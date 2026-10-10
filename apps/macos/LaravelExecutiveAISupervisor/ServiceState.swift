// Service model for the Supervisor (S1-07, ADR-001 D3, docs/architecture/services.md).
//
// Three supervised children, one state each. The state is driven by the health contract
// (docs/architecture/health-contract.md) and by process exits — never by "the process exists"
// alone: a FrankenPHP that is running but not answering `/health/live` is `degraded`, not healthy.

import Foundation

/// The three children of ADR-001 D3, in the order they are started.
enum ServiceName: String, CaseIterable, Codable, Sendable, Hashable {
    /// `frankenphp run --config Caddyfile` — the loopback HTTP core.
    case core
    /// `frankenphp php-cli app/artisan queue:work database …`.
    case worker
    /// `frankenphp php-cli app/artisan schedule:work`.
    case scheduler

    /// The `/health/ready` check that drives this service's state. The core is driven by
    /// `/health/live` instead (health contract §1: liveness decides restarts).
    var readyCheckName: String? {
        switch self {
        case .core: return nil
        case .worker: return "queue_worker"
        case .scheduler: return "scheduler_selftest"
        }
    }
}

/// Per-child state machine (services.md §3).
enum ServiceState: String, Codable, Sendable, Equatable {
    /// Spawned (or scheduled to respawn after backoff) and not yet confirmed by a probe.
    case starting
    /// The process runs and its health signal is `ok`.
    case healthy
    /// The process runs but its health signal fails or is skipped; a restart may follow.
    case degraded
    /// The restart bound was exceeded, or boot-time verification refused to spawn anything.
    /// Nothing is retried until a person acts (ADR-001 D3).
    case failed
    /// Stopped on request (`stopAll`), or never started.
    case stopped
}

/// Why a child left. Short, sanitised, fit for the menu bar and the log.
struct ServiceExit: Equatable, Sendable, CustomStringConvertible {
    enum Reason: Equatable, Sendable {
        case exited(status: Int32)
        case signalled(signal: Int32)
        case spawnFailed
        /// The Supervisor killed it because its health signal failed repeatedly.
        case unhealthy(detail: String)
    }

    let reason: Reason
    let at: Date

    var description: String {
        switch reason {
        case .exited(let status): return "exited with status \(status)"
        case .signalled(let signal): return "terminated by signal \(signal)"
        case .spawnFailed: return "could not be started"
        case .unhealthy(let detail): return "stopped by the Supervisor: \(detail)"
        }
    }
}

/// What the XPC `services()` call returns (ipc-contract §7.2). `healthy` is the contract's
/// `running`; `failed` is the post-bound state the contract asks to surface.
struct ServiceStatus: Codable, Equatable, Sendable {
    let name: ServiceName
    let state: ServiceState
    let pid: Int32?
    let restartsInWindow: Int
    let lastExitReason: String?
    let since: Date
    /// Sanitised one-liner for the UI: never a path with a user name, never a secret.
    let detail: String?

    enum CodingKeys: String, CodingKey {
        case name
        case state
        case pid
        case restartsInWindow = "restarts_in_window"
        case lastExitReason = "last_exit_reason"
        case since
        case detail
    }
}
