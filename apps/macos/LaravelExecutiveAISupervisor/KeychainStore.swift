// The Keychain behind a protocol (ADR-003 D1, D2).
//
// `SecItemKeychainStore` writes generic-password items into the data-protection keychain with
// exactly the attributes ADR-003 D1 fixes: `kSecUseDataProtectionKeychain`, never
// synchronisable, `AfterFirstUnlock` so the 05:00 brief can read with the screen locked, the one
// shared access group, and a label without PII. It only works from a signed process that holds
// the group (the Supervisor); an unsigned build gets `errSecMissingEntitlement` and fails closed.
// `InMemoryKeychainStore` is what tests use — nothing in the test suite touches the real
// Keychain.

import Foundation
import Security
import Synchronization

struct KeychainItemRef: Hashable, Sendable, CustomStringConvertible {
    let service: String
    let account: String

    var description: String { "\(service)/\(account)" }
}

enum KeychainError: Error, Equatable, Sendable {
    case unavailable(String)
    case missingEntitlement
    case status(OSStatus)
    case encoding
}

protocol KeychainStore: Sendable {
    /// The item's data, or nil when there is none.
    func read(_ ref: KeychainItemRef) throws -> Data?
    /// Creates or replaces the item atomically.
    func write(_ ref: KeychainItemRef, data: Data, label: String) throws
    /// Removes the item; a missing item is not an error.
    func delete(_ ref: KeychainItemRef) throws
    /// Accounts under a service — never values (ADR-003 D3.1 `list`).
    func accounts(service: String) throws -> [String]
}

final class SecItemKeychainStore: KeychainStore {
    /// `<TEAMID>.se.stafe.executive-ai.secrets` once A12 is decided; nil only in unsigned
    /// developer runs, where the default private group is used.
    let accessGroup: String?

    init(accessGroup: String?) {
        self.accessGroup = accessGroup
    }

    func read(_ ref: KeychainItemRef) throws -> Data? {
        var query = baseQuery(ref)
        query[kSecReturnData as String] = true
        query[kSecMatchLimit as String] = kSecMatchLimitOne

        var result: CFTypeRef?
        let status = SecItemCopyMatching(query as CFDictionary, &result)

        switch status {
        case errSecSuccess:
            return result as? Data
        case errSecItemNotFound:
            return nil
        default:
            throw Self.map(status)
        }
    }

    func write(_ ref: KeychainItemRef, data: Data, label: String) throws {
        let update: [String: Any] = [
            kSecValueData as String: data,
            kSecAttrLabel as String: label,
        ]

        let updateStatus = SecItemUpdate(baseQuery(ref) as CFDictionary, update as CFDictionary)

        if updateStatus == errSecSuccess {
            return
        }

        guard updateStatus == errSecItemNotFound else {
            throw Self.map(updateStatus)
        }

        var attributes = baseQuery(ref)
        attributes[kSecValueData as String] = data
        attributes[kSecAttrLabel as String] = label
        attributes[kSecAttrAccessible as String] = kSecAttrAccessibleAfterFirstUnlock

        let addStatus = SecItemAdd(attributes as CFDictionary, nil)

        guard addStatus == errSecSuccess else {
            throw Self.map(addStatus)
        }
    }

    func delete(_ ref: KeychainItemRef) throws {
        let status = SecItemDelete(baseQuery(ref) as CFDictionary)

        guard status == errSecSuccess || status == errSecItemNotFound else {
            throw Self.map(status)
        }
    }

    func accounts(service: String) throws -> [String] {
        var query: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: service,
            kSecUseDataProtectionKeychain as String: true,
            kSecAttrSynchronizable as String: false,
            kSecReturnAttributes as String: true,
            kSecMatchLimit as String: kSecMatchLimitAll,
        ]

        if let accessGroup {
            query[kSecAttrAccessGroup as String] = accessGroup
        }

        var result: CFTypeRef?
        let status = SecItemCopyMatching(query as CFDictionary, &result)

        switch status {
        case errSecSuccess:
            let items = result as? [[String: Any]] ?? []
            return items.compactMap { $0[kSecAttrAccount as String] as? String }.sorted()
        case errSecItemNotFound:
            return []
        default:
            throw Self.map(status)
        }
    }

    private func baseQuery(_ ref: KeychainItemRef) -> [String: Any] {
        var query: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: ref.service,
            kSecAttrAccount as String: ref.account,
            kSecUseDataProtectionKeychain as String: true,
            kSecAttrSynchronizable as String: false,
        ]

        if let accessGroup {
            query[kSecAttrAccessGroup as String] = accessGroup
        }

        return query
    }

    private static func map(_ status: OSStatus) -> KeychainError {
        status == errSecMissingEntitlement ? .missingEntitlement : .status(status)
    }
}

/// Tests and the DEBUG simulation. Records writes so a test can assert what was stored.
final class InMemoryKeychainStore: KeychainStore {
    struct Item: Equatable, Sendable {
        let data: Data
        let label: String
    }

    private let items = Mutex<[KeychainItemRef: Item]>([:])
    private let failure: KeychainError?

    init(failingWith failure: KeychainError? = nil) {
        self.failure = failure
    }

    var snapshot: [KeychainItemRef: Item] {
        items.withLock { $0 }
    }

    func read(_ ref: KeychainItemRef) throws -> Data? {
        try fail()
        return items.withLock { $0[ref]?.data }
    }

    func write(_ ref: KeychainItemRef, data: Data, label: String) throws {
        try fail()
        items.withLock { $0[ref] = Item(data: data, label: label) }
    }

    func delete(_ ref: KeychainItemRef) throws {
        try fail()
        items.withLock { _ = $0.removeValue(forKey: ref) }
    }

    func accounts(service: String) throws -> [String] {
        try fail()
        return items.withLock { $0.keys.filter { $0.service == service }.map(\.account).sorted() }
    }

    private func fail() throws {
        if let failure {
            throw failure
        }
    }
}
