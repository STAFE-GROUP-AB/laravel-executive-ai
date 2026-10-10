// Health probe (S1-05 defines, S1-08 implements).
//
// HEALTH_CHECK → READY is gated on `GET /health/ready` returning `ready: true`
// (docs/architecture/health-contract.md). That route requires the `supervisor`-scoped IPC token
// (ipc-contract §2, §6.1), which the IPC client of S1-08 obtains through pairing. This file only
// fixes the shape the state machine depends on; the real client replaces `UnavailableHealthProbe`
// in `InstallDependencies.live`.

import Foundation

/// The subset of the `/health/ready` document the installer reads. Never the raw body.
struct HealthReadiness: Equatable, Sendable {
    let ready: Bool
    /// `checks[].name` entries whose status is not `ok`, in document order. Names only — no detail
    /// text crosses into the installer.
    let failingChecks: [String]
    /// `ai_state`: `not_configured`, `configured_untested`, `ready` or `error`.
    let aiState: String

    static let coreReadyNoAI = HealthReadiness(ready: true, failingChecks: [], aiState: "not_configured")

    /// True when the Done screen must say "AI setup required" (health contract §3, blueprint §0).
    var aiSetupRequired: Bool {
        aiState != "ready"
    }
}

enum HealthProbeError: Error, Equatable, Sendable {
    /// No probe is wired in this build (S1-08 pending).
    case unavailable(String)
    /// The core did not answer on loopback.
    case unreachable(String)
    case invalidResponse
}

protocol HealthProbe: Sendable {
    func readiness() async throws -> HealthReadiness
}

/// Fails every time, so a build without the S1-08 client can never reach READY.
struct UnavailableHealthProbe: HealthProbe {
    func readiness() async throws -> HealthReadiness {
        throw HealthProbeError.unavailable("The health probe arrives with the IPC client (S1-08).")
    }
}

/// Returns scripted results in order; the last one repeats. Tests and the DEBUG simulation.
final class ScriptedHealthProbe: HealthProbe, @unchecked Sendable {
    private let lock = NSLock()
    private var script: [Result<HealthReadiness, HealthProbeError>]
    private(set) var calls = 0

    init(_ script: [Result<HealthReadiness, HealthProbeError>]) {
        precondition(!script.isEmpty)
        self.script = script
    }

    convenience init(ready: HealthReadiness = .coreReadyNoAI) {
        self.init([.success(ready)])
    }

    func readiness() async throws -> HealthReadiness {
        let next = lock.withLock {
            calls += 1

            return script.count > 1 ? script.removeFirst() : script[0]
        }

        return try next.get()
    }
}
