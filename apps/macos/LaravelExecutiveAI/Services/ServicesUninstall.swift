// The service half of uninstall (ADR-003 D8; runtime-layout.md §1 "Uninstall"), in the order
// S1-13 must call it: stop and unregister the LaunchAgent first, so launchd cannot respawn a
// Supervisor that would recreate the items; then delete every `se.stafe.executive-ai.*` Keychain
// item; then (S1-13) `RuntimeUninstaller.remove(preservingData:in:)` and `BriefCache.clear()`.
//
// Keychain deletion from the GUI process needs the shared access group the Supervisor holds
// (ADR-003 D2); S1-13 decides whether this runs through the Supervisor before it is unregistered
// or through the uninstaller's own entitlement. The order is fixed here either way.

import Foundation

struct ServicesUninstallReport: Equatable, Sendable {
    let agentUnregistered: Bool
    let secretsRemoved: [KeychainItemRef]
}

enum ServicesUninstallError: Error, Equatable, Sendable {
    case unregisterFailed(String)
    case secretsFailed(String)
}

struct ServicesUninstaller: Sendable {
    let registrar: any LaunchAgentRegistering
    let secrets: SupervisorSecrets

    func remove() async throws -> ServicesUninstallReport {
        var unregistered = false

        if registrar.status() != .notRegistered {
            do {
                try await registrar.unregister()
                unregistered = true
            } catch {
                throw ServicesUninstallError.unregisterFailed("the launch agent could not be unregistered")
            }
        }

        let removed: [KeychainItemRef]

        do {
            removed = try secrets.removeAll()
        } catch {
            throw ServicesUninstallError.secretsFailed("the Keychain items could not be removed")
        }

        return ServicesUninstallReport(agentUnregistered: unregistered, secretsRemoved: removed)
    }
}
