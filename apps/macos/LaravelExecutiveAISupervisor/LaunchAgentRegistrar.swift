// launchd registration of the one Supervisor LaunchAgent (ADR-001 D3) behind a protocol.
//
// `SMAppServiceRegistrar` is the real, thin wrapper around `SMAppService.agent(plistName:)`.
// `RecordingRegistrar` is what every test and the DEBUG simulation use: nothing in this
// repository's tests ever registers a real agent on the machine running them (S1-14 does that
// on a lab Mac). API consumers:
//
//   - `register()`            S1-07 `LaunchAgentServiceBooter` from the installer's BOOT_SERVICES
//   - `unregister()`          S1-13 uninstall — call it before `RuntimeUninstaller.remove` and
//                             before deleting the Keychain items, so the agent cannot respawn
//                             a Supervisor that would recreate them
//   - `status()`              preflight 6.8 (`SystemPreflightProbes`) and the booter
//   - `openLoginItemsSettings()`  the installer's "Open Login Items" action

import Foundation
import ServiceManagement

enum LaunchAgentStatus: String, Equatable, Sendable {
    /// Never registered, or unregistered.
    case notRegistered
    /// Registered and allowed to run.
    case enabled
    /// Registered, but the person must allow it in System Settings › General › Login Items.
    case requiresApproval
    /// The plist is not in the bundle: a packaging defect, not a user problem.
    case notFound
}

enum LaunchAgentError: Error, Equatable, Sendable {
    case registrationFailed(String)
    case unregistrationFailed(String)
}

protocol LaunchAgentRegistering: Sendable {
    func register() throws
    func unregister() async throws
    func status() -> LaunchAgentStatus
    func openLoginItemsSettings()
}

/// The real thing. Lives in the GUI app process (registration is the app's job; the Supervisor
/// is what gets registered).
struct SMAppServiceRegistrar: LaunchAgentRegistering {
    let plistName: String

    private var service: SMAppService {
        SMAppService.agent(plistName: plistName)
    }

    func register() throws {
        do {
            try service.register()
        } catch {
            throw LaunchAgentError.registrationFailed(Self.describe(error))
        }
    }

    func unregister() async throws {
        do {
            try await service.unregister()
        } catch {
            throw LaunchAgentError.unregistrationFailed(Self.describe(error))
        }
    }

    func status() -> LaunchAgentStatus {
        switch service.status {
        case .notRegistered: return .notRegistered
        case .enabled: return .enabled
        case .requiresApproval: return .requiresApproval
        case .notFound: return .notFound
        @unknown default: return .notRegistered
        }
    }

    func openLoginItemsSettings() {
        SMAppService.openSystemSettingsLoginItems()
    }

    /// `SMAppService` errors are `NSError`s with a code and a generic description; the
    /// description never contains a path or a user name.
    private static func describe(_ error: any Error) -> String {
        let nsError = error as NSError
        return "\(nsError.domain) \(nsError.code)"
    }
}

/// Records every call in order and answers `status()` from a script. Tests and the simulation.
final class RecordingRegistrar: LaunchAgentRegistering, @unchecked Sendable {
    enum Call: Equatable, Sendable {
        case register
        case unregister
        case status
        case openLoginItemsSettings
    }

    private let lock = NSLock()
    private var statuses: [LaunchAgentStatus]
    private let registerError: LaunchAgentError?
    private let unregisterError: LaunchAgentError?
    private(set) var calls: [Call] = []

    /// `statuses` answers successive `status()` calls; the last one repeats.
    init(statuses: [LaunchAgentStatus] = [.enabled], registerError: LaunchAgentError? = nil, unregisterError: LaunchAgentError? = nil) {
        precondition(!statuses.isEmpty)
        self.statuses = statuses
        self.registerError = registerError
        self.unregisterError = unregisterError
    }

    func register() throws {
        lock.withLock { calls.append(.register) }

        if let registerError {
            throw registerError
        }
    }

    func unregister() async throws {
        lock.withLock {
            calls.append(.unregister)
            statuses = [.notRegistered]
        }

        if let unregisterError {
            throw unregisterError
        }
    }

    func status() -> LaunchAgentStatus {
        lock.withLock {
            calls.append(.status)
            return statuses.count > 1 ? statuses.removeFirst() : statuses[0]
        }
    }

    func openLoginItemsSettings() {
        lock.withLock { calls.append(.openLoginItemsSettings) }
    }
}
