// GUI ↔ Supervisor control surface (ipc-contract §7): pairing, elevation and service control.
//
// `SupervisorControlling` is the Swift-native protocol both ends program against;
// `SupervisorControlService` is the in-process implementation that runs inside the Supervisor,
// tested directly. `SupervisorControlXPC.swift` carries it over `NSXPCConnection`.

import Foundation
import LocalAuthentication

enum SupervisorControlError: Error, Equatable, Sendable {
    /// The control channel could not be reached (Supervisor not running, requirement failed).
    case unreachable(String)
    case secrets(String)
    case authenticationFailed
    case authenticationUnavailable(String)
    case refused(String)
    case invalidArgument(String)
}

protocol SupervisorControlling: Sendable {
    /// §7.1 — one live session per client; pairing again replaces it.
    func pair(client: IpcClient) async throws -> PairingGrant
    /// §7.1 — regenerates `K`; every token derived from the old one dies at once.
    func resetPairing() async throws -> PairingGrant
    /// §7.2
    func services() async throws -> [ServiceStatus]
    func restart(service: ServiceName) async throws
    func stopAll() async throws
    func startAll() async throws
    /// §7.3 — device-owner authentication, then a one-shot elevation token.
    func elevate(sessionId: String, operation: String, nonce: String) async throws -> Elevation
    /// Installer SELECT_AI hand-off: the key goes straight to the Keychain through the one
    /// writer, never through the GUI's own Keychain or a file.
    func storeProviderApiKey(provider: String, apiKey: TransientSecret) async throws
    /// §7.2 (S1-13) — uninstall: deletes every `se.stafe.executive-ai.*` Keychain item through
    /// the one writer (ADR-003 D2, D8) and drops every live session. Returns what was removed —
    /// names, never values. Called before the LaunchAgent is unregistered, because only the
    /// Supervisor holds the access group.
    func removeAllSecrets() async throws -> [KeychainItemRef]
}

/// `LAContext.evaluatePolicy(.deviceOwnerAuthentication)` behind a protocol.
protocol DeviceOwnerAuthenticating: Sendable {
    func authenticate(reason: String) async throws
}

struct LocalAuthenticationDeviceOwner: DeviceOwnerAuthenticating {
    func authenticate(reason: String) async throws {
        let context = LAContext()
        var error: NSError?

        guard context.canEvaluatePolicy(.deviceOwnerAuthentication, error: &error) else {
            throw SupervisorControlError.authenticationUnavailable("\(error?.code ?? -1)")
        }

        do {
            guard try await context.evaluatePolicy(.deviceOwnerAuthentication, localizedReason: reason) else {
                throw SupervisorControlError.authenticationFailed
            }
        } catch let error as SupervisorControlError {
            throw error
        } catch {
            throw SupervisorControlError.authenticationFailed
        }
    }
}

final class ScriptedDeviceOwnerAuthenticator: DeviceOwnerAuthenticating, @unchecked Sendable {
    private let lock = NSLock()
    private let succeeds: Bool
    private(set) var reasons: [String] = []

    init(succeeds: Bool) {
        self.succeeds = succeeds
    }

    func authenticate(reason: String) async throws {
        lock.withLock { reasons.append(reason) }

        guard succeeds else {
            throw SupervisorControlError.authenticationFailed
        }
    }
}

/// Runs inside the Supervisor. Holds the live `K` (read from the Keychain at start, replaced on
/// reset), mints grants and elevations, and forwards process control to the supervisor.
actor SupervisorControlService: SupervisorControlling {
    private let secrets: SupervisorSecrets
    private let supervisor: ServiceSupervisor
    private let port: Int
    private let authenticator: any DeviceOwnerAuthenticating
    private let now: @Sendable () -> Date
    private let random: @Sendable (Int) -> Data
    /// Re-runs boot-time verification before `startAll` after a `failed` boot.
    private let beforeStart: @Sendable () async throws -> Void
    private var key: IpcKey
    private var sessions: [IpcClient: String] = [:]
    private var keyChanged: [@Sendable (IpcKey) -> Void] = []

    init(
        secrets: SupervisorSecrets,
        key: IpcKey,
        supervisor: ServiceSupervisor,
        port: Int,
        authenticator: any DeviceOwnerAuthenticating,
        now: @escaping @Sendable () -> Date = { Date() },
        random: @escaping @Sendable (Int) -> Data = SecureRandom.bytes,
        beforeStart: @escaping @Sendable () async throws -> Void = {}
    ) {
        self.secrets = secrets
        self.key = key
        self.supervisor = supervisor
        self.port = port
        self.authenticator = authenticator
        self.now = now
        self.random = random
        self.beforeStart = beforeStart
    }

    /// Who needs the new `K` after a reset (the health probe's grant, for one).
    func onKeyChanged(_ handler: @escaping @Sendable (IpcKey) -> Void) {
        keyChanged.append(handler)
    }

    var currentSessions: [IpcClient: String] {
        sessions
    }

    func pair(client: IpcClient) async throws -> PairingGrant {
        let grant = PairingGrant.mint(key: key, client: client, port: port, now: now(), random: random)
        sessions[client] = grant.sessionId

        return grant
    }

    func resetPairing() async throws -> PairingGrant {
        do {
            key = try secrets.resetPairing()
        } catch {
            throw SupervisorControlError.secrets("the pairing key could not be regenerated")
        }

        sessions.removeAll()
        keyChanged.forEach { $0(key) }

        return try await pair(client: .menubar)
    }

    func services() async throws -> [ServiceStatus] {
        await supervisor.statuses()
    }

    func restart(service: ServiceName) async throws {
        await supervisor.restart(service)
    }

    func stopAll() async throws {
        await supervisor.stopAll()
    }

    func startAll() async throws {
        do {
            try await beforeStart()
        } catch {
            // The sanitised reason, never an enum's debug description: this string reaches
            // the menu bar.
            throw SupervisorControlError.refused((error as? SupervisorBootError)?.userFacingReason ?? "verification failed")
        }

        await supervisor.startAll()
    }

    func elevate(sessionId: String, operation: String, nonce: String) async throws -> Elevation {
        guard sessions[.menubar] == sessionId else {
            throw SupervisorControlError.refused("no live session with that id")
        }

        guard Self.isOperationSlug(operation) else {
            throw SupervisorControlError.invalidArgument("operation")
        }

        guard nonce.count == 22, nonce.allSatisfy({ $0.isASCII && ($0.isLetter || $0.isNumber || $0 == "-" || $0 == "_") }) else {
            throw SupervisorControlError.invalidArgument("nonce")
        }

        try await authenticator.authenticate(reason: "Laravel Executive AI needs your approval to \(operation.replacingOccurrences(of: "-", with: " ")).")

        let expiresAt = now().addingTimeInterval(Elevation.lifetime)
        let expiresAtSeconds = Int(expiresAt.timeIntervalSince1970)

        return Elevation(
            value: IpcSigning.elevation(key: key, sessionId: sessionId, operation: operation, nonce: nonce, expiresAt: expiresAtSeconds),
            operation: operation,
            nonce: nonce,
            expiresAt: expiresAt
        )
    }

    func storeProviderApiKey(provider: String, apiKey: TransientSecret) async throws {
        guard AiProviderID(rawValue: provider) != nil else {
            throw SupervisorControlError.invalidArgument("provider")
        }

        do {
            try secrets.storeProviderApiKey(provider: provider, apiKey: apiKey)
        } catch {
            throw SupervisorControlError.secrets("the API key could not be stored")
        }
    }

    func removeAllSecrets() async throws -> [KeychainItemRef] {
        let removed: [KeychainItemRef]

        do {
            removed = try secrets.removeAll()
        } catch {
            throw SupervisorControlError.secrets("the Keychain items could not be removed")
        }

        // `K` is gone from the Keychain; nothing paired against it may elevate any more. The
        // in-memory key stays only so this process can keep answering until it is unregistered.
        sessions.removeAll()

        return removed
    }

    static func isOperationSlug(_ value: String) -> Bool {
        guard let first = value.first, first.isLowercase, first.isLetter, value.count <= 64 else {
            return false
        }

        return value.allSatisfy { $0.isASCII && (($0.isLetter && $0.isLowercase) || $0.isNumber || $0 == "-") }
    }
}
