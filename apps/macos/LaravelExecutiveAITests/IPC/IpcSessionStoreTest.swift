// The session store (ipc-contract §3.1 row 2, §3.4): the in-memory store round-trips a grant; the
// Keychain store's query dictionaries carry exactly the attributes the contract fixes — generic
// password, data-protection keychain, never synchronisable, `AfterFirstUnlock`, **no** access
// group — built through pure functions, so the real Keychain is never touched by a test; and the
// session actor persists after pairing, survives a store that refuses writes, and forgets on request.

import Foundation
import Security
import XCTest
@testable import LaravelExecutiveAI

final class IpcSessionStoreTest: XCTestCase {

    // MARK: - In-memory store

    func testInMemoryStoreRoundTripsAGrantAndClears() throws {
        let store = InMemorySessionStore()
        XCTAssertNil(try store.load())

        let grant = IpcTestSupport.grant()
        try store.save(grant)

        XCTAssertEqual(try store.load(), grant)
        XCTAssertEqual(store.saves, 1)

        try store.clear()

        XCTAssertNil(try store.load())
        XCTAssertEqual(store.clears, 1)
    }

    func testAFailingStoreThrowsItsFailure() {
        let store = InMemorySessionStore(failingWith: .missingEntitlement)

        XCTAssertThrowsError(try store.load()) { XCTAssertEqual($0 as? SessionStoreError, .missingEntitlement) }
        XCTAssertThrowsError(try store.save(IpcTestSupport.grant()))
        XCTAssertThrowsError(try store.clear())
    }

    // MARK: - Keychain item shape

    func testTheItemIsNamedAfterTheBundlePrefix() {
        let store = KeychainSessionStore.default(bundleIdentifier: "se.stafe.executive-ai.app")

        XCTAssertEqual(store.service, "se.stafe.executive-ai.ipc.menubar.session")
        XCTAssertEqual(store.account, "default")
        XCTAssertEqual(KeychainSessionStore.service(bundlePrefix: "com.example.prefix"), "com.example.prefix.ipc.menubar.session")
        XCTAssertNotEqual(store.service, SecretService.ipcMenubar, "the GUI's item is not the Supervisor's `K` item")
    }

    func testTheBaseQueryIsAPrivateDataProtectionGenericPasswordWithoutAnAccessGroup() {
        let query = KeychainSessionStore.baseQuery(service: "se.stafe.executive-ai.ipc.menubar.session", account: "default")

        XCTAssertEqual(query[kSecClass as String] as? String, kSecClassGenericPassword as String)
        XCTAssertEqual(query[kSecAttrService as String] as? String, "se.stafe.executive-ai.ipc.menubar.session")
        XCTAssertEqual(query[kSecAttrAccount as String] as? String, "default")
        XCTAssertEqual(query[kSecUseDataProtectionKeychain as String] as? Bool, true)
        XCTAssertEqual(query[kSecAttrSynchronizable as String] as? Bool, false)
        XCTAssertNil(query[kSecAttrAccessGroup as String], "the GUI holds no access group (ADR-003 D5)")
        XCTAssertEqual(Set(query.keys), [kSecClass as String, kSecAttrService as String, kSecAttrAccount as String, kSecUseDataProtectionKeychain as String, kSecAttrSynchronizable as String])
    }

    func testTheReadQueryReturnsOneItemsDataAndNothingMore() {
        let query = KeychainSessionStore.readQuery(service: "s", account: "a")

        XCTAssertEqual(query[kSecReturnData as String] as? Bool, true)
        XCTAssertEqual(query[kSecMatchLimit as String] as? String, kSecMatchLimitOne as String)
        XCTAssertNil(query[kSecReturnAttributes as String])
        XCTAssertNil(query[kSecAttrAccessGroup as String])
    }

    func testTheAddAttributesAreAccessibleAfterFirstUnlockWithAPiiFreeLabel() {
        let data = Data("payload".utf8)
        let attributes = KeychainSessionStore.addAttributes(service: "s", account: "a", data: data)

        XCTAssertEqual(attributes[kSecValueData as String] as? Data, data)
        XCTAssertEqual(attributes[kSecAttrAccessible as String] as? String, kSecAttrAccessibleAfterFirstUnlock as String)
        XCTAssertEqual(attributes[kSecAttrLabel as String] as? String, "Laravel Executive AI — IPC session")
        XCTAssertEqual(attributes[kSecUseDataProtectionKeychain as String] as? Bool, true)
        XCTAssertEqual(attributes[kSecAttrSynchronizable as String] as? Bool, false)
        XCTAssertNil(attributes[kSecAttrAccessGroup as String])
        XCTAssertFalse(KeychainSessionStore.label.contains("@"))

        let update = KeychainSessionStore.updateAttributes(data: data)
        XCTAssertEqual(Set(update.keys), [kSecValueData as String, kSecAttrLabel as String])
    }

    func testTheStoredBytesAreTheWireFormOfTheGrantAndRoundTrip() throws {
        let grant = IpcTestSupport.grant()
        let bytes = try KeychainSessionStore.encode(grant)
        let object = try XCTUnwrap(JSONSerialization.jsonObject(with: bytes) as? [String: Any])

        XCTAssertEqual(Set(object.keys), ["v", "client", "session_id", "token", "signing_key", "issued_at", "expires_at", "port", "ipc_version"])
        XCTAssertEqual(try KeychainSessionStore.decode(bytes), grant)
        XCTAssertThrowsError(try KeychainSessionStore.decode(Data("{}".utf8))) { XCTAssertEqual($0 as? SessionStoreError, .encoding) }
    }

    // MARK: - The session actor

    func testPairingPersistsTheGrantOnce() async throws {
        let store = InMemorySessionStore()
        let control = StubSupervisorControl()
        let session = IpcTestSupport.session(control: control, store: store)

        let first = try await session.currentGrant()
        let second = try await session.currentGrant()

        XCTAssertEqual(first, second)
        XCTAssertEqual(control.pairCalls, 1)
        XCTAssertEqual(store.saves, 1)
        XCTAssertEqual(store.stored, first)
        let unavailable = await session.storeUnavailable
        XCTAssertFalse(unavailable)
    }

    func testAStoreThatRefusesWritesKeepsTheGrantInMemoryForThisProcess() async throws {
        let store = InMemorySessionStore(failingWith: .missingEntitlement)
        let control = StubSupervisorControl()
        let session = IpcTestSupport.session(control: control, store: store)

        let grant = try await session.currentGrant()
        let again = try await session.currentGrant()

        XCTAssertEqual(grant, again)
        XCTAssertEqual(control.pairCalls, 1, "an unsigned build pairs once per launch, not once per request")
        let unavailable = await session.storeUnavailable
        XCTAssertTrue(unavailable)
    }

    func testAStoredGrantOfAnotherClientOrVersionIsIgnored() async throws {
        let supervisorGrant = IpcTestSupport.grant(client: .supervisor)
        let control = StubSupervisorControl(pair: [.success(IpcTestSupport.grant())])
        let session = IpcTestSupport.session(control: control, store: InMemorySessionStore(grant: supervisorGrant))

        let grant = try await session.currentGrant()

        XCTAssertEqual(grant.client, .menubar)
        XCTAssertEqual(control.pairCalls, 1)
    }

    func testConcurrentFirstUsesPairOnlyOnce() async throws {
        let control = StubSupervisorControl()
        let session = IpcTestSupport.session(grant: nil, control: control)

        let grants = try await withThrowingTaskGroup(of: PairingGrant.self) { group in
            for _ in 0..<4 {
                group.addTask { try await session.currentGrant() }
            }

            var collected: [PairingGrant] = []

            for try await grant in group {
                collected.append(grant)
            }

            return collected
        }

        XCTAssertEqual(Set(grants.map(\.sessionId)).count, 1)
        XCTAssertEqual(control.pairCalls, 1)
    }

    func testForgetDropsMemoryAndTheStore() async throws {
        let store = InMemorySessionStore(grant: IpcTestSupport.grant())
        let session = IpcTestSupport.session(control: StubSupervisorControl(), store: store)
        _ = try await session.currentGrant()

        await session.forget()

        let has = await session.hasGrant
        XCTAssertFalse(has)
        XCTAssertNil(store.stored)
        XCTAssertEqual(store.clears, 1)
    }

    func testTheUninstallerClearsTheSessionItemWithTheBriefCache() async throws {
        let root = FileManager.default.temporaryDirectory.appending(path: "lxai-ipc-uninstall-\(UUID().uuidString)")
        try FileManager.default.createDirectory(at: root, withIntermediateDirectories: true)
        defer { try? FileManager.default.removeItem(at: root) }
        let paths = InstallPaths.rooted(at: root)
        let store = InMemorySessionStore(grant: IpcTestSupport.grant())

        let dependencies = UninstallDependencies(
            paths: paths,
            productVersion: "0.1.0",
            control: ScriptedSupervisorControl(),
            registrar: RecordingRegistrar(statuses: [.notRegistered]),
            briefCache: BriefCache(paths: paths),
            checkpointStore: InMemoryInstallCheckpointStore(),
            journalStore: InMemoryUninstallJournalStore(),
            appBundleRemover: RecordingAppBundleRemover(),
            appBundleURL: nil,
            now: { Date() },
            afterStep: nil,
            sessionStore: store
        )

        let report = try await Uninstaller(dependencies: dependencies).uninstall(choice: .delete)

        XCTAssertTrue(report.performed.contains(.briefCacheCleared))
        XCTAssertEqual(store.clears, 1)
        XCTAssertNil(store.stored)
    }
}
