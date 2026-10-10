// The menu bar's `ServiceControlling` (MenuBar/MenuBarPorts.swift) over the Supervisor's XPC
// surface (S1-07; ipc-contract §6.8, §7.2).
//
// "Restart services" is stop-all then start-all through the Supervisor, which re-runs the
// boot-time runtime verification before spawning. "Reset security pairing" regenerates `K` in
// the Supervisor and clears the brief cache (ipc-contract §6.2). Uninstall stays with S1-13,
// which calls `LaunchAgentRegistering.unregister()` and `SupervisorSecrets.removeAll()`.

import Foundation

struct SupervisorServiceControl: ServiceControlling {
    let control: any SupervisorControlling
    let cache: BriefCache

    func restartServices() async throws {
        do {
            try await control.stopAll()
            try await control.startAll()
        } catch let error as SupervisorControlError {
            throw ServiceControlError.unavailable(Self.describe(error))
        }
    }

    func stopServices() async throws {
        do {
            try await control.stopAll()
        } catch let error as SupervisorControlError {
            throw ServiceControlError.unavailable(Self.describe(error))
        }
    }

    func uninstall() async throws {
        throw ServiceControlError.unavailable("Uninstall arrives with S1-13.")
    }

    /// Settings → Diagnostics → "Reset security pairing" (ipc-contract §3.4, §7.1). Returns the
    /// fresh grant; storing it in the GUI's private Keychain item is S1-08 part 2.
    func resetPairing() async throws -> PairingGrant {
        let grant: PairingGrant

        do {
            grant = try await control.resetPairing()
        } catch let error as SupervisorControlError {
            throw ServiceControlError.unavailable(Self.describe(error))
        }

        try? cache.clear()

        return grant
    }

    private static func describe(_ error: SupervisorControlError) -> String {
        switch error {
        case .unreachable: return "The Supervisor did not answer."
        case .secrets: return "The Supervisor could not update its secrets."
        case .authenticationFailed: return "Authentication was cancelled."
        case .authenticationUnavailable: return "Authentication is not available on this Mac."
        case .refused(let reason): return "The Supervisor refused: \(reason)."
        case .invalidArgument: return "The request was malformed."
        }
    }
}
