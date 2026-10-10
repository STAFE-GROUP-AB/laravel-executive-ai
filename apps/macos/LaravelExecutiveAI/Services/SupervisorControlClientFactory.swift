// Builds the GUI's XPC client for the Supervisor from the app bundle (S1-07): Mach service
// `<prefix>.supervisor.control`, the code-signing requirement from the app's own Team ID
// (A12 — one constant), and the DEBUG-only unsigned-peer escape hatch for lab runs.

import Foundation

enum SupervisorControlClientFactory {
    static func live(bundle: Bundle = .main, environment: [String: String] = ProcessInfo.processInfo.environment) -> any SupervisorControlling {
        let bundleIdentifier = bundle.bundleIdentifier ?? SupervisorIdentity.defaultBundlePrefix + ".app"
        let prefix = SupervisorIdentity.bundlePrefix(fromAppIdentifier: bundleIdentifier)
        let requirement = SupervisorIdentity.runningTeamIdentifier().map(SupervisorControlWire.codeSigningRequirement(teamIdentifier:))

        var allowUnsignedPeer = false

        #if DEBUG
        allowUnsignedPeer = environment["LXAI_SUPERVISOR_ALLOW_UNSIGNED_PEERS"] == "1"
        #endif

        return XPCSupervisorControlClient(
            machServiceName: SupervisorControlWire.machServiceName(bundlePrefix: prefix),
            requirement: requirement,
            allowUnsignedPeer: allowUnsignedPeer
        )
    }
}
