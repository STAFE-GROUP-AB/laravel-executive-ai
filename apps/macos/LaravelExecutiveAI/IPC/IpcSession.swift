// The GUI's IPC session (S1-08 part 2; ipc-contract §3): one `PairingGrant` obtained from the
// Supervisor over XPC (`pair(client: .menubar)`, §7.1) and kept in the GUI's **own** Keychain item
// — generic password, data-protection keychain, never synchronisable, no access group (the GUI
// holds none; ADR-003 D5). The token and signing key live in that item and in this actor's
// memory, nowhere else: never a plist, never UserDefaults, never a log line.
//
// Lifecycle (§3.2 step 4): the grant is loaded on first use; a missing grant, or one that expires
// within the renewal window, is replaced by pairing again — silently, no dialog. A `401` /
// `session_invalid` or `signature_invalid` answer means `K` was rotated: the store is consulted
// first ("Reset security pairing" writes the fresh grant there), then the Supervisor is asked
// once. Uninstall and reset go through `forget()` / `replace(with:)`.

import Foundation
import Security
import Synchronization

protocol SessionStore: Sendable {
    /// The stored grant, or nil when there is none.
    func load() throws -> PairingGrant?
    /// Creates or replaces the item atomically.
    func save(_ grant: PairingGrant) throws
    /// Removes the item; a missing item is not an error.
    func clear() throws
}

enum SessionStoreError: Error, Equatable, Sendable {
    case keychain(OSStatus)
    /// The data-protection keychain refuses an unsigned process (`errSecMissingEntitlement`).
    case missingEntitlement
    case encoding
}

/// The GUI's private generic-password item (ipc-contract §3.1, row 2).
struct KeychainSessionStore: SessionStore {
    static let defaultAccount = "default"

    let service: String
    let account: String

    init(service: String, account: String = KeychainSessionStore.defaultAccount) {
        self.service = service
        self.account = account
    }

    /// `<prefix>.ipc.menubar.session` for the app `<prefix>.app` (A12 placeholder prefix).
    static func `default`(bundleIdentifier: String) -> KeychainSessionStore {
        KeychainSessionStore(service: service(bundlePrefix: SupervisorIdentity.bundlePrefix(fromAppIdentifier: bundleIdentifier)))
    }

    static func service(bundlePrefix: String) -> String {
        "\(bundlePrefix).ipc.menubar.session"
    }

    /// Shown in Keychain Access. Product name and item kind only; nothing about the user.
    static let label = "Laravel Executive AI — IPC session"

    // MARK: Query shape (pure; `IpcSessionStoreTest` pins the attributes)

    static func baseQuery(service: String, account: String) -> [String: Any] {
        [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: service,
            kSecAttrAccount as String: account,
            kSecUseDataProtectionKeychain as String: true,
            kSecAttrSynchronizable as String: false,
        ]
    }

    static func readQuery(service: String, account: String) -> [String: Any] {
        var query = baseQuery(service: service, account: account)
        query[kSecReturnData as String] = true
        query[kSecMatchLimit as String] = kSecMatchLimitOne

        return query
    }

    static func addAttributes(service: String, account: String, data: Data) -> [String: Any] {
        var attributes = baseQuery(service: service, account: account)
        attributes[kSecValueData as String] = data
        attributes[kSecAttrLabel as String] = label
        attributes[kSecAttrAccessible as String] = kSecAttrAccessibleAfterFirstUnlock

        return attributes
    }

    static func updateAttributes(data: Data) -> [String: Any] {
        [
            kSecValueData as String: data,
            kSecAttrLabel as String: label,
        ]
    }

    // MARK: SessionStore

    func load() throws -> PairingGrant? {
        var result: CFTypeRef?
        let status = SecItemCopyMatching(Self.readQuery(service: service, account: account) as CFDictionary, &result)

        switch status {
        case errSecSuccess:
            guard let data = result as? Data else {
                throw SessionStoreError.encoding
            }

            return try Self.decode(data)
        case errSecItemNotFound:
            return nil
        default:
            throw Self.map(status)
        }
    }

    func save(_ grant: PairingGrant) throws {
        let data = try Self.encode(grant)
        let updateStatus = SecItemUpdate(Self.baseQuery(service: service, account: account) as CFDictionary, Self.updateAttributes(data: data) as CFDictionary)

        if updateStatus == errSecSuccess {
            return
        }

        guard updateStatus == errSecItemNotFound else {
            throw Self.map(updateStatus)
        }

        let addStatus = SecItemAdd(Self.addAttributes(service: service, account: account, data: data) as CFDictionary, nil)

        guard addStatus == errSecSuccess else {
            throw Self.map(addStatus)
        }
    }

    func clear() throws {
        let status = SecItemDelete(Self.baseQuery(service: service, account: account) as CFDictionary)

        guard status == errSecSuccess || status == errSecItemNotFound else {
            throw Self.map(status)
        }
    }

    static func encode(_ grant: PairingGrant) throws -> Data {
        do {
            return try PairingGrant.encoder().encode(grant)
        } catch {
            throw SessionStoreError.encoding
        }
    }

    static func decode(_ data: Data) throws -> PairingGrant {
        do {
            return try PairingGrant.decoder().decode(PairingGrant.self, from: data)
        } catch {
            throw SessionStoreError.encoding
        }
    }

    private static func map(_ status: OSStatus) -> SessionStoreError {
        status == errSecMissingEntitlement ? .missingEntitlement : .keychain(status)
    }
}

/// Tests and the DEBUG simulation. Records saves so a test can assert what was stored.
final class InMemorySessionStore: SessionStore {
    private let grant: Mutex<PairingGrant?>
    private let failure: SessionStoreError?
    private let counters = Mutex<(saves: Int, clears: Int)>((0, 0))

    init(grant: PairingGrant? = nil, failingWith failure: SessionStoreError? = nil) {
        self.grant = Mutex(grant)
        self.failure = failure
    }

    var stored: PairingGrant? {
        grant.withLock { $0 }
    }

    var saves: Int {
        counters.withLock { $0.saves }
    }

    var clears: Int {
        counters.withLock { $0.clears }
    }

    func load() throws -> PairingGrant? {
        try fail()

        return grant.withLock { $0 }
    }

    func save(_ grant: PairingGrant) throws {
        try fail()
        self.grant.withLock { $0 = grant }
        counters.withLock { $0.saves += 1 }
    }

    func clear() throws {
        try fail()
        grant.withLock { $0 = nil }
        counters.withLock { $0.clears += 1 }
    }

    private func fail() throws {
        if let failure {
            throw failure
        }
    }
}

enum IpcSessionError: Error, Equatable, Sendable {
    /// The Supervisor's control channel did not answer: not running, unsigned peer, timeout.
    case supervisorUnreachable(String)
    /// The Supervisor answered and refused to pair (sanitised reason).
    case pairingRefused(String)

    static func from(_ error: SupervisorControlError) -> IpcSessionError {
        switch error {
        case .unreachable: .supervisorUnreachable("the Supervisor did not answer")
        case .secrets: .pairingRefused("the Supervisor could not read its secrets")
        case .authenticationFailed, .authenticationUnavailable: .pairingRefused("authentication was not available")
        case .refused(let reason): .pairingRefused(reason)
        case .invalidArgument: .pairingRefused("the pairing request was malformed")
        }
    }

    var userFacingReason: String {
        switch self {
        case .supervisorUnreachable(let reason), .pairingRefused(let reason): reason
        }
    }
}

/// The grant the client signs with, and the rules for replacing it.
actor IpcSession {
    /// ipc-contract §3.2 step 4: re-pair when `expires_at` is within 24 h.
    static let renewalWindow: TimeInterval = 24 * 3600

    private let store: any SessionStore
    private let control: any SupervisorControlling
    private let now: @Sendable () -> Date
    private let renewalWindow: TimeInterval
    private var grant: PairingGrant?
    private var loadedFromStore = false
    private var inFlightPairing: Task<PairingGrant, any Error>?
    private(set) var pairings = 0
    /// True once the store refused a write: the grant then lives in memory for this process only
    /// (an unsigned build has no data-protection keychain) and pairing repeats at the next launch.
    private(set) var storeUnavailable = false

    init(
        store: any SessionStore,
        control: any SupervisorControlling,
        now: @escaping @Sendable () -> Date = { Date() },
        renewalWindow: TimeInterval = IpcSession.renewalWindow
    ) {
        self.store = store
        self.control = control
        self.now = now
        self.renewalWindow = renewalWindow
    }

    /// The grant for the next request: the one in memory, else the stored one; a missing grant or
    /// one inside the renewal window is replaced by pairing again.
    func currentGrant() async throws -> PairingGrant {
        if grant == nil, !loadedFromStore {
            loadedFromStore = true
            grant = loadStored()
        }

        if let grant, !needsRenewal(grant) {
            return grant
        }

        return try await pair()
    }

    /// §8.2 `session_invalid` / `signature_invalid`: the key was rotated. "Reset security
    /// pairing" writes the fresh grant to the store, so that is checked first; otherwise the
    /// Supervisor is asked once. Returns the replacement.
    func replaceInvalid(_ invalid: PairingGrant) async throws -> PairingGrant {
        if grant?.sessionId == invalid.sessionId {
            grant = nil
        }

        if let stored = loadStored(), stored.sessionId != invalid.sessionId, !needsRenewal(stored) {
            grant = stored

            return stored
        }

        return try await pair()
    }

    /// `resetPairing()` returned a fresh grant: use it from now on and persist it.
    func replace(with fresh: PairingGrant) {
        grant = fresh
        loadedFromStore = true
        persist(fresh)
    }

    /// Uninstall: drop the grant from memory and from the store.
    func forget() {
        grant = nil
        loadedFromStore = true

        try? store.clear()
    }

    var hasGrant: Bool {
        grant != nil
    }

    private func needsRenewal(_ grant: PairingGrant) -> Bool {
        grant.expiresAt.timeIntervalSince(now()) <= renewalWindow
    }

    private func loadStored() -> PairingGrant? {
        guard let stored = try? store.load(), stored.client == .menubar, stored.v == 1 else {
            return nil
        }

        return stored
    }

    private func persist(_ grant: PairingGrant) {
        do {
            try store.save(grant)
            storeUnavailable = false
        } catch {
            storeUnavailable = true
        }
    }

    private func pair() async throws -> PairingGrant {
        if let inFlightPairing {
            return try await inFlightPairing.value
        }

        let control = self.control
        let task = Task<PairingGrant, any Error> {
            do {
                return try await control.pair(client: .menubar)
            } catch let error as SupervisorControlError {
                throw IpcSessionError.from(error)
            } catch {
                throw IpcSessionError.supervisorUnreachable("pairing failed")
            }
        }

        inFlightPairing = task

        defer {
            inFlightPairing = nil
        }

        let fresh = try await task.value
        pairings += 1
        grant = fresh
        loadedFromStore = true
        persist(fresh)

        return fresh
    }
}
