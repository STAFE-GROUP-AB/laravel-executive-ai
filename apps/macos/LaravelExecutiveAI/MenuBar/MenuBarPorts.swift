// The side effects the panel model can cause, each behind a protocol so tests can prove which
// ones a path touches (S1-09). In particular: "Quit UI" (§1.1) reaches `AppTerminating` only —
// never `ServiceControlling`, which is the GUI ↔ Supervisor XPC surface (ipc-contract §6.8/§7,
// implemented by S1-07) that stops, restarts or uninstalls the local services.

import AppKit
import Foundation

protocol URLOpening: Sendable {
    func open(_ url: URL)
}

protocol AppTerminating: Sendable {
    /// Ends this GUI process. Nothing else: the Supervisor LaunchAgent keeps the core alive.
    func terminateUI()
}

protocol SettingsPresenting: Sendable {
    func presentSettings()
}

/// GUI ↔ Supervisor service control (ipc-contract §6.8, §7.2). Stop and uninstall are destructive
/// and elevated; the panel's only caller is "Restart services" in the unreachable/not-ready states.
protocol ServiceControlling: Sendable {
    func restartServices() async throws
    func stopServices() async throws
    func uninstall() async throws
}

enum ServiceControlError: Error, Equatable, Sendable {
    case unavailable(String)
}

struct WorkspaceURLOpener: URLOpening {
    func open(_ url: URL) {
        NSWorkspace.shared.open(url)
    }
}

struct ApplicationTerminator: AppTerminating {
    func terminateUI() {
        Task { @MainActor in
            NSApplication.shared.terminate(nil)
        }
    }
}

/// Opens the SwiftUI `Settings` scene (the app menu's "Settings…", ⌘,) and brings the app forward,
/// which a menu bar panel does not do by itself.
struct SettingsSceneOpener: SettingsPresenting {
    func presentSettings() {
        Task { @MainActor in
            NSApplication.shared.activate()
            NSApplication.shared.sendAction(Selector(("showSettingsWindow:")), to: nil, from: nil)
        }
    }
}

/// Fails closed. The live binding is `SupervisorServiceControl` (Services/) over the XPC client;
/// this stays for tests and for a build without a control channel.
struct UnavailableServiceControl: ServiceControlling {
    func restartServices() async throws {
        throw ServiceControlError.unavailable("Service control arrives with the Supervisor (S1-07).")
    }

    func stopServices() async throws {
        throw ServiceControlError.unavailable("Service control arrives with the Supervisor (S1-07).")
    }

    func uninstall() async throws {
        throw ServiceControlError.unavailable("Service control arrives with the Supervisor (S1-07).")
    }
}

/// Everything the model needs besides the core client and the cache.
struct PanelPorts: Sendable {
    var urlOpener: any URLOpening
    var terminator: any AppTerminating
    var settings: any SettingsPresenting
    var serviceControl: any ServiceControlling

    /// Service control goes GUI → Supervisor over XPC (ipc-contract §6.8, §7.2; S1-07). The
    /// client and the brief cache are built from the bundle identifier like everything else.
    static let live = PanelPorts(
        urlOpener: WorkspaceURLOpener(),
        terminator: ApplicationTerminator(),
        settings: SettingsSceneOpener(),
        serviceControl: SupervisorServiceControl(
            control: SupervisorControlClientFactory.live(),
            cache: BriefCache.default(bundleIdentifier: Bundle.main.bundleIdentifier ?? "se.stafe.executive-ai.app")
        )
    )
}
