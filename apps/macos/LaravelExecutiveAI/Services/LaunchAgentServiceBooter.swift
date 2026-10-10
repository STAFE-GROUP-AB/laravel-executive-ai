// BOOT_SERVICES, live (S1-07; S1-05 defined the hand-off).
//
// Registers the one Supervisor LaunchAgent (ADR-001 D3) and waits until the Supervisor answers
// on its control channel. `.requiresLoginItemApproval` is returned when macOS wants the person
// to allow the login item — a click in System Settings, never a password (§12.2); the installer
// re-enters this state after the click. The API key from SELECT_AI, if any, is handed to the
// Supervisor here: SELECT_AI runs before BOOT_SERVICES, so the key waits in memory
// (`PendingSecretHandoff`) and goes through the one Keychain writer (ADR-003 D2) as soon as it
// exists. The installer never writes the Keychain itself.

import Foundation
import Synchronization

/// Holds the SELECT_AI key between the two states. Cleared on hand-off; never persisted.
final class PendingSecretHandoff: Sendable {
    private let pending = Mutex<(provider: AiProviderID, key: TransientSecret)?>(nil)

    func set(provider: AiProviderID, key: TransientSecret) {
        pending.withLock { $0 = (provider, key) }
    }

    func take() -> (provider: AiProviderID, key: TransientSecret)? {
        pending.withLock { value in
            let current = value
            value = nil
            return current
        }
    }

    var isEmpty: Bool {
        pending.withLock { $0 == nil }
    }
}

/// `AiSelectionApplying` for the live app: `skip` and a detected local runtime need nothing from
/// the Supervisor in Sprint 1 (the choice is in the checkpoint; the core-side provider selection
/// is the AI adapters' item); an own key is parked for `LaunchAgentServiceBooter`.
struct SupervisorAiSelectionApplier: AiSelectionApplying {
    let pending: PendingSecretHandoff

    func apply(_ submission: AiSelectionSubmission) async throws {
        guard case .ownApiKey(let provider) = submission.selection else {
            return
        }

        guard let key = submission.apiKey else {
            throw AiSelectionError.apiKeyMissing
        }

        pending.set(provider: provider, key: key)
    }
}

struct LaunchAgentServiceBooter: ServiceBooting {
    struct Waiting: Sendable {
        /// How often and how long to ask the Supervisor for `services()` after registration.
        var attempts = 20
        var interval: Duration = .milliseconds(500)
        var sleep: @Sendable (Duration) async throws -> Void = { try await Task.sleep(for: $0) }

        init() {}
    }

    let registrar: any LaunchAgentRegistering
    let control: any SupervisorControlling
    let pending: PendingSecretHandoff
    var waiting = Waiting()

    init(registrar: any LaunchAgentRegistering, control: any SupervisorControlling, pending: PendingSecretHandoff, waiting: Waiting = Waiting()) {
        self.registrar = registrar
        self.control = control
        self.pending = pending
        self.waiting = waiting
    }

    func boot(runtime: InstalledRuntime, port: Int) async throws -> BootServicesOutcome {
        switch registrar.status() {
        case .enabled:
            break
        case .notRegistered, .requiresApproval, .notFound:
            do {
                try registrar.register()
            } catch let error as LaunchAgentError {
                throw ServiceBootError.failed(Self.describe(error))
            } catch {
                throw ServiceBootError.failed("The Supervisor could not be registered with launchd.")
            }
        }

        switch registrar.status() {
        case .requiresApproval:
            return .requiresLoginItemApproval
        case .notFound:
            throw ServiceBootError.failed("This copy of the app has no Supervisor launch agent; reinstall the app.")
        case .notRegistered:
            throw ServiceBootError.failed("launchd did not keep the Supervisor registration.")
        case .enabled:
            break
        }

        try await waitForSupervisor()
        try await handOffPendingSecret()

        return .started
    }

    func openLoginItemsSettings() {
        registrar.openLoginItemsSettings()
    }

    private func waitForSupervisor() async throws {
        for attempt in 1...max(waiting.attempts, 1) {
            try Task.checkCancellation()

            if (try? await control.services()) != nil {
                return
            }

            if attempt < waiting.attempts {
                try await waiting.sleep(waiting.interval)
            }
        }

        throw ServiceBootError.failed("The Supervisor was registered but did not answer on its control channel.")
    }

    private func handOffPendingSecret() async throws {
        guard let (provider, key) = pending.take() else {
            return
        }

        do {
            try await control.storeProviderApiKey(provider: provider.rawValue, apiKey: key)
        } catch {
            // Put it back so a Retry can hand it off again; the key never touches disk.
            pending.set(provider: provider, key: key)
            throw ServiceBootError.failed("The Supervisor could not store the AI provider key.")
        }
    }

    private static func describe(_ error: LaunchAgentError) -> String {
        switch error {
        case .registrationFailed(let detail): return "The Supervisor could not be registered with launchd (\(detail))."
        case .unregistrationFailed(let detail): return "The Supervisor could not be unregistered (\(detail))."
        }
    }
}
