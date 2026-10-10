// ADR-003 D1/D2/D8 with the in-memory Keychain: first-boot generation, idempotence, the
// all-zero refusal, reset, the provider key envelope and the uninstall sweep.

import Foundation
import XCTest
@testable import LaravelExecutiveAI

final class SupervisorSecretsTests: XCTestCase {
    private func secrets(_ store: InMemoryKeychainStore, random: @escaping @Sendable (Int) -> Data = SecureRandom.bytes) -> SupervisorSecrets {
        SupervisorSecrets(store: store, random: random, now: { Date(timeIntervalSince1970: 1_791_648_000) })
    }

    func testFirstBootGeneratesAppKeyAndIsIdempotent() throws {
        let store = InMemoryKeychainStore()
        let secrets = secrets(store)

        let first = try secrets.ensureAppKey()
        let second = try secrets.ensureAppKey()

        XCTAssertEqual(first, second)
        XCTAssertEqual(first.kind, "laravel_app_key")
        XCTAssertEqual(first.v, 1)
        XCTAssertTrue(first.current.hasPrefix("base64:"))
        XCTAssertEqual(Data(base64Encoded: String(first.current.dropFirst(7)))?.count, 32)
        XCTAssertEqual(first.previous, [])
        XCTAssertEqual(first.issuedAt, "2026-10-10T16:00:00Z")

        let item = try XCTUnwrap(store.snapshot[SupervisorSecrets.appKeyRef])
        XCTAssertEqual(item.label, "Laravel Executive AI — core.app-key")
        XCTAssertEqual(store.snapshot.count, 1)

        let json = try XCTUnwrap(JSONSerialization.jsonObject(with: item.data) as? [String: Any])
        XCTAssertEqual(json["current"] as? String, first.current)
    }

    func testIpcKeyIsGeneratedOnceAndNeverAllZero() throws {
        let store = InMemoryKeychainStore()
        let secrets = secrets(store)

        let key = try secrets.ensureIpcKey()
        XCTAssertEqual(key.bytes.count, 32)
        XCTAssertFalse(IpcKey.isAllZero(key.bytes))
        XCTAssertEqual(try secrets.ensureIpcKey(), key)

        let item = try XCTUnwrap(store.snapshot[SupervisorSecrets.ipcKeyRef])
        let envelope = try JSONDecoder().decode(HmacKeyEnvelope.self, from: item.data)
        XCTAssertEqual(envelope.kind, "hmac_key")
        XCTAssertEqual(Data(base64Encoded: envelope.key), key.bytes)
    }

    func testAllZeroRandomnessIsRefused() {
        let zero = secrets(InMemoryKeychainStore()) { count in Data(repeating: 0, count: count) }

        XCTAssertThrowsError(try zero.resetPairing()) { error in
            XCTAssertEqual(error as? SupervisorSecretsError, .allZeroKey)
        }

        XCTAssertNil(IpcKey(Data(repeating: 0, count: 32)))
        XCTAssertNil(IpcKey(Data(repeating: 1, count: 31)))
    }

    func testStoredAllZeroKeyIsReplacedOnRead() throws {
        let store = InMemoryKeychainStore()
        let zeroEnvelope = HmacKeyEnvelope(v: 1, kind: "hmac_key", key: Data(repeating: 0, count: 32).base64EncodedString(), issuedAt: "2026-01-01T00:00:00Z")
        try store.write(SupervisorSecrets.ipcKeyRef, data: JSONEncoder().encode(zeroEnvelope), label: "x")

        let key = try secrets(store).ensureIpcKey()

        XCTAssertFalse(IpcKey.isAllZero(key.bytes))
    }

    func testResetPairingReplacesTheKey() throws {
        let secrets = secrets(InMemoryKeychainStore())
        let before = try secrets.ensureIpcKey()
        let after = try secrets.resetPairing()

        XCTAssertNotEqual(before, after)
        XCTAssertEqual(try secrets.ensureIpcKey(), after)
    }

    func testCorruptEnvelopeFailsClosed() throws {
        let store = InMemoryKeychainStore()
        try store.write(SupervisorSecrets.appKeyRef, data: Data("not json".utf8), label: "x")

        XCTAssertThrowsError(try secrets(store).ensureAppKey()) { error in
            XCTAssertEqual(error as? SupervisorSecretsError, .corruptEnvelope(SecretService.appKey))
        }
    }

    func testProviderApiKeyEnvelope() throws {
        let store = InMemoryKeychainStore()
        let secrets = secrets(store)

        try secrets.storeProviderApiKey(provider: "openai", apiKey: TransientSecret("sk-test-not-a-real-key-0123456789")!)

        let ref = KeychainItemRef(service: SecretService.aiProvider, account: "openai")
        let item = try XCTUnwrap(store.snapshot[ref])
        let json = try XCTUnwrap(JSONSerialization.jsonObject(with: item.data) as? [String: Any])

        XCTAssertEqual(json["kind"] as? String, "api_key")
        XCTAssertEqual(json["provider"] as? String, "openai")
        XCTAssertEqual(json["api_key"] as? String, "sk-test-not-a-real-key-0123456789")
        XCTAssertEqual(item.label, "Laravel Executive AI — ai-provider")
    }

    func testRemoveAllSweepsTheInventory() throws {
        let store = InMemoryKeychainStore()
        let secrets = secrets(store)
        try secrets.ensureAppKey()
        _ = try secrets.ensureIpcKey()
        try secrets.storeProviderApiKey(provider: "mistral", apiKey: TransientSecret("not-a-real-key-0123456789")!)
        try store.write(KeychainItemRef(service: "se.other.app", account: "x"), data: Data([1]), label: "other")

        let removed = try secrets.removeAll()

        XCTAssertEqual(removed.count, 3)
        XCTAssertEqual(store.snapshot.count, 1, "items outside the inventory are not ours to delete")
        XCTAssertNotNil(store.snapshot[KeychainItemRef(service: "se.other.app", account: "x")])
    }

    func testKeychainFailureIsReported() {
        let secrets = secrets(InMemoryKeychainStore(failingWith: .missingEntitlement))

        XCTAssertThrowsError(try secrets.ensureAppKey()) { error in
            XCTAssertEqual(error as? SupervisorSecretsError, .keychain(.missingEntitlement))
        }
    }

    func testInventoryIsPrefixIndependentOfTheBundleId() {
        XCTAssertEqual(SecretService.inventory.count, 10)

        for service in SecretService.inventory {
            XCTAssertTrue(service.hasPrefix("se.stafe.executive-ai."), service)
        }

        XCTAssertEqual(SecretService.appKey, "se.stafe.executive-ai.core.app-key")
        XCTAssertEqual(SecretService.ipcMenubar, "se.stafe.executive-ai.ipc.menubar")
    }
}
