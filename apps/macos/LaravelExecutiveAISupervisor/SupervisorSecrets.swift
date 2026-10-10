// The secrets the Supervisor owns (ADR-003 D1, D2, D8; ipc-contract §3.1).
//
// On first boot it generates `APP_KEY` (32 random bytes, Laravel's `base64:` form) and `K`
// (32 random bytes, the IPC HMAC key) and writes them to the Keychain. It is the only Keychain
// writer in the product. "Reset security pairing" regenerates `K`; an all-zero `K` — the
// fixture key — is refused on write and treated as absent on read. Service names are the
// ADR-003 D1 inventory and are independent of the bundle prefix (A12 never forces a migration).

import Foundation

enum SecretService {
    static let prefix = "se.stafe.executive-ai"

    static let appKey = "\(prefix).core.app-key"
    static let ipcMenubar = "\(prefix).ipc.menubar"
    static let email = "\(prefix).email"
    static let emailSender = "\(prefix).email-sender"
    static let github = "\(prefix).github"
    static let aiProvider = "\(prefix).ai-provider"
    static let mcp = "\(prefix).mcp"
    static let webpush = "\(prefix).webpush"
    static let tunnel = "\(prefix).tunnel"
    static let backup = "\(prefix).backup"

    /// ADR-003 D1 inventory: the only services the broker answers for, and what the
    /// uninstaller enumerates (D8 "LIKE se.stafe.executive-ai.*").
    static let inventory: [String] = [appKey, ipcMenubar, email, emailSender, github, aiProvider, mcp, webpush, tunnel, backup]

    static let defaultAccount = "default"

    static func label(_ service: String) -> String {
        "Laravel Executive AI — \(service.dropFirst(prefix.count + 1))"
    }
}

/// `kind: laravel_app_key` envelope — `current` plus `previous[]` for ADR-003 D8 rotation.
struct AppKeyEnvelope: Codable, Equatable, Sendable {
    let v: Int
    let kind: String
    let current: String
    let previous: [String]
    let issuedAt: String

    enum CodingKeys: String, CodingKey {
        case v
        case kind
        case current
        case previous
        case issuedAt = "issued_at"
    }

    static let kind = "laravel_app_key"
}

/// `kind: hmac_key` envelope for `K` (ipc-contract §3.1).
struct HmacKeyEnvelope: Codable, Equatable, Sendable {
    let v: Int
    let kind: String
    let key: String
    let issuedAt: String

    enum CodingKeys: String, CodingKey {
        case v
        case kind
        case key
        case issuedAt = "issued_at"
    }

    static let kind = "hmac_key"
}

enum SupervisorSecretsError: Error, Equatable, Sendable {
    case keychain(KeychainError)
    case corruptEnvelope(String)
    case allZeroKey
}

struct SupervisorSecrets: Sendable {
    let store: any KeychainStore
    let random: @Sendable (Int) -> Data
    let now: @Sendable () -> Date

    init(store: any KeychainStore, random: @escaping @Sendable (Int) -> Data = SecureRandom.bytes, now: @escaping @Sendable () -> Date = { Date() }) {
        self.store = store
        self.random = random
        self.now = now
    }

    static let appKeyRef = KeychainItemRef(service: SecretService.appKey, account: SecretService.defaultAccount)
    static let ipcKeyRef = KeychainItemRef(service: SecretService.ipcMenubar, account: SecretService.defaultAccount)

    // MARK: APP_KEY

    /// Returns the stored envelope, generating and storing one on first boot.
    @discardableResult
    func ensureAppKey() throws -> AppKeyEnvelope {
        if let data = try read(Self.appKeyRef) {
            guard let envelope = try? JSONDecoder().decode(AppKeyEnvelope.self, from: data), envelope.kind == AppKeyEnvelope.kind else {
                throw SupervisorSecretsError.corruptEnvelope(SecretService.appKey)
            }

            return envelope
        }

        let envelope = AppKeyEnvelope(
            v: 1,
            kind: AppKeyEnvelope.kind,
            current: "base64:" + random(32).base64EncodedString(),
            previous: [],
            issuedAt: Self.timestamp(now())
        )

        try write(Self.appKeyRef, envelope, label: SecretService.label(SecretService.appKey))

        return envelope
    }

    // MARK: K

    /// Returns `K`, generating it on first boot. A stored all-zero key is replaced.
    func ensureIpcKey() throws -> IpcKey {
        if let data = try read(Self.ipcKeyRef) {
            guard let envelope = try? JSONDecoder().decode(HmacKeyEnvelope.self, from: data), envelope.kind == HmacKeyEnvelope.kind,
                  let bytes = Data(base64Encoded: envelope.key)
            else {
                throw SupervisorSecretsError.corruptEnvelope(SecretService.ipcMenubar)
            }

            if let key = IpcKey(bytes) {
                return key
            }
        }

        return try resetPairing()
    }

    /// "Reset security pairing" (ADR-003 D8, ipc-contract §3.4): a fresh `K`; every token
    /// derived from the old one is dead at once.
    func resetPairing() throws -> IpcKey {
        let bytes = random(IpcKey.byteCount)

        guard let key = IpcKey(bytes) else {
            throw SupervisorSecretsError.allZeroKey
        }

        let envelope = HmacKeyEnvelope(v: 1, kind: HmacKeyEnvelope.kind, key: bytes.base64EncodedString(), issuedAt: Self.timestamp(now()))
        try write(Self.ipcKeyRef, envelope, label: SecretService.label(SecretService.ipcMenubar))

        return key
    }

    // MARK: Provider API key (installer SELECT_AI → broker)

    /// Stores the person's own API key under `ai-provider/<provider id>`. Sprint 1 keys the
    /// item by adapter id; when `ai_providers` rows exist (ADR-004) the account becomes the
    /// row's ULID and this item is migrated by the core through `SecretStore::put`.
    func storeProviderApiKey(provider: String, apiKey: TransientSecret) throws {
        let ref = KeychainItemRef(service: SecretService.aiProvider, account: provider)

        let data: Data = try apiKey.use { value in
            let envelope: [String: Any] = ["v": 1, "kind": "api_key", "provider": provider, "api_key": value, "obtained_at": Self.timestamp(now())]

            guard JSONSerialization.isValidJSONObject(envelope),
                  let data = try? JSONSerialization.data(withJSONObject: envelope, options: [.sortedKeys])
            else {
                throw SupervisorSecretsError.corruptEnvelope(SecretService.aiProvider)
            }

            return data
        }

        try write(ref, data, label: SecretService.label(SecretService.aiProvider))
    }

    // MARK: Uninstall (ADR-003 D8; S1-13)

    /// Deletes every item of every service in the inventory. Returns what was removed.
    @discardableResult
    func removeAll() throws -> [KeychainItemRef] {
        var removed: [KeychainItemRef] = []

        for service in SecretService.inventory {
            for account in try map({ try store.accounts(service: service) }) {
                let ref = KeychainItemRef(service: service, account: account)
                try map { try store.delete(ref) }
                removed.append(ref)
            }
        }

        return removed
    }

    // MARK: Helpers

    private func read(_ ref: KeychainItemRef) throws -> Data? {
        try map { try store.read(ref) }
    }

    private func write<T: Encodable>(_ ref: KeychainItemRef, _ envelope: T, label: String) throws {
        let encoder = JSONEncoder()
        encoder.outputFormatting = [.sortedKeys]

        guard let data = try? encoder.encode(envelope) else {
            throw SupervisorSecretsError.corruptEnvelope(ref.service)
        }

        try write(ref, data, label: label)
    }

    private func write(_ ref: KeychainItemRef, _ data: Data, label: String) throws {
        try map { try store.write(ref, data: data, label: label) }
    }

    private func map<T>(_ body: () throws -> T) throws -> T {
        do {
            return try body()
        } catch let error as KeychainError {
            throw SupervisorSecretsError.keychain(error)
        }
    }

    static func timestamp(_ date: Date) -> String {
        ISO8601DateFormatter().string(from: date)
    }
}
