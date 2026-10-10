// ipc-contract §7 in process: pairing grants, reset, elevation, service control and the
// provider-key hand-off, with the in-memory Keychain and a scripted authenticator.

import Foundation
import XCTest
@testable import LaravelExecutiveAI

final class SupervisorControlServiceTests: XCTestCase {
    private let now = Date(timeIntervalSince1970: 1_791_648_000)

    private func makeService(
        authenticator: ScriptedDeviceOwnerAuthenticator = ScriptedDeviceOwnerAuthenticator(succeeds: true),
        beforeStart: @escaping @Sendable () async throws -> Void = {}
    ) throws -> (SupervisorControlService, SupervisorSecrets, ServiceSupervisor, InMemoryKeychainStore) {
        let now = self.now
        let store = InMemoryKeychainStore()
        let secrets = SupervisorSecrets(store: store, now: { now })
        let key = try secrets.ensureIpcKey()
        let supervisor = ServiceSupervisor(specs: [ServicesTestSupport.shellSpec(.core, "sleep 30")], configuration: ServicesTestSupport.fastConfiguration())
        let service = SupervisorControlService(secrets: secrets, key: key, supervisor: supervisor, port: 8792, authenticator: authenticator, now: { now }, beforeStart: beforeStart)

        return (service, secrets, supervisor, store)
    }

    func testPairMintsAGrantVerifiableUnderK() async throws {
        let (service, secrets, _, _) = try makeService()
        let key = try secrets.ensureIpcKey()

        let grant = try await service.pair(client: .menubar)

        XCTAssertEqual(grant.client, .menubar)
        XCTAssertEqual(grant.port, 8792)
        XCTAssertEqual(grant.ipcVersion, 1)
        XCTAssertEqual(grant.token, IpcSigning.token(key: key, client: .menubar, sessionId: grant.sessionId, expiresAt: grant.expiresAtSeconds))
        XCTAssertEqual(grant.signingKeyData, IpcSigning.signingKey(key: key, client: .menubar, sessionId: grant.sessionId))
        XCTAssertEqual(grant.expiresAt, now.addingTimeInterval(30 * 24 * 3600))

        // One live session per client: pairing again replaces it.
        let second = try await service.pair(client: .menubar)
        let sessions = await service.currentSessions
        XCTAssertEqual(sessions[.menubar], second.sessionId)
        XCTAssertNotEqual(second.sessionId, grant.sessionId)
    }

    func testResetPairingRotatesKAndInvalidatesOldGrants() async throws {
        let (service, secrets, _, store) = try makeService()
        let oldKey = try secrets.ensureIpcKey()
        let oldGrant = try await service.pair(client: .menubar)

        let observed = ObservedKeys()
        await service.onKeyChanged { observed.append($0) }

        let fresh = try await service.resetPairing()
        let newKey = try secrets.ensureIpcKey()

        XCTAssertNotEqual(newKey, oldKey)
        XCTAssertEqual(observed.keys, [newKey])
        XCTAssertEqual(fresh.token, IpcSigning.token(key: newKey, client: .menubar, sessionId: fresh.sessionId, expiresAt: fresh.expiresAtSeconds))
        XCTAssertNotEqual(IpcSigning.token(key: newKey, client: .menubar, sessionId: oldGrant.sessionId, expiresAt: oldGrant.expiresAtSeconds), oldGrant.token, "the old token is dead")
        XCTAssertEqual(store.snapshot.count, 1, "still exactly one K item")
    }

    func testElevationRequiresALiveSessionAndDeviceOwnerAuthentication() async throws {
        let authenticator = ScriptedDeviceOwnerAuthenticator(succeeds: true)
        let (service, secrets, _, _) = try makeService(authenticator: authenticator)
        let key = try secrets.ensureIpcKey()
        let grant = try await service.pair(client: .menubar)
        let nonce = IpcSigning.nonce()

        let elevation = try await service.elevate(sessionId: grant.sessionId, operation: "remote-access-enable", nonce: nonce)

        XCTAssertEqual(elevation.value, IpcSigning.elevation(key: key, sessionId: grant.sessionId, operation: "remote-access-enable", nonce: nonce, expiresAt: Int(now.timeIntervalSince1970) + 60))
        XCTAssertEqual(elevation.expiresAt, now.addingTimeInterval(60))
        XCTAssertEqual(authenticator.reasons, ["Laravel Executive AI needs your approval to remote access enable."])

        do {
            _ = try await service.elevate(sessionId: "01M4G0QK2T6Z5P9Y3R7W1A8C4E", operation: "remote-access-enable", nonce: nonce)
            XCTFail("unknown session")
        } catch let error as SupervisorControlError {
            XCTAssertEqual(error, .refused("no live session with that id"))
        }

        do {
            _ = try await service.elevate(sessionId: grant.sessionId, operation: "Remote Access", nonce: nonce)
            XCTFail("bad slug")
        } catch let error as SupervisorControlError {
            XCTAssertEqual(error, .invalidArgument("operation"))
        }

        do {
            _ = try await service.elevate(sessionId: grant.sessionId, operation: "remote-access-enable", nonce: "short")
            XCTFail("bad nonce")
        } catch let error as SupervisorControlError {
            XCTAssertEqual(error, .invalidArgument("nonce"))
        }

        let (denied, _, _, _) = try makeService(authenticator: ScriptedDeviceOwnerAuthenticator(succeeds: false))
        let deniedGrant = try await denied.pair(client: .menubar)

        do {
            _ = try await denied.elevate(sessionId: deniedGrant.sessionId, operation: "remote-access-enable", nonce: nonce)
            XCTFail("authentication failed")
        } catch let error as SupervisorControlError {
            XCTAssertEqual(error, .authenticationFailed)
        }
    }

    func testServiceControlGoesThroughTheSupervisorAndStartReVerifies() async throws {
        let verifications = Counter()
        let (service, _, supervisor, _) = try makeService(beforeStart: { verifications.increment() })

        try await service.startAll()
        XCTAssertEqual(verifications.value, 1)
        let started = await ServicesTestSupport.waitUntil { await ServicesTestSupport.status(supervisor, .core)?.pid != nil }
        XCTAssertTrue(started)

        let statuses = try await service.services()
        XCTAssertEqual(statuses.map(\.name), [.core])
        XCTAssertEqual(statuses[0].state, .starting)

        try await service.stopAll()
        let stopped = try await service.services()
        XCTAssertEqual(stopped[0].state, .stopped)

        let (refusing, _, refusingSupervisor, _) = try makeService(beforeStart: { throw SupervisorBootError.verificationFailed("checksum") })

        do {
            try await refusing.startAll()
            XCTFail("verification must gate start")
        } catch let error as SupervisorControlError {
            XCTAssertEqual(error, .refused("The installed runtime failed verification (checksum)."))
        }

        let untouched = await refusingSupervisor.statuses()
        XCTAssertEqual(untouched[0].state, .stopped, "nothing spawned when verification refuses")
    }

    func testProviderKeyIsStoredUnderTheProviderAccount() async throws {
        let (service, _, _, store) = try makeService()

        try await service.storeProviderApiKey(provider: "anthropic", apiKey: TransientSecret("sk-ant-not-a-real-key-0123456789")!)

        XCTAssertNotNil(store.snapshot[KeychainItemRef(service: SecretService.aiProvider, account: "anthropic")])

        do {
            try await service.storeProviderApiKey(provider: "not-a-provider", apiKey: TransientSecret("x-not-a-real-key-0123456789")!)
            XCTFail("unknown provider")
        } catch let error as SupervisorControlError {
            XCTAssertEqual(error, .invalidArgument("provider"))
        }
    }

    func testWireCodesRoundTrip() {
        for error: SupervisorControlError in [.unreachable("x"), .secrets("x"), .authenticationFailed, .authenticationUnavailable("x"), .refused("why"), .invalidArgument("nonce")] {
            let code = SupervisorControlWire.code(for: error)
            XCTAssertFalse(code.isEmpty)

            switch SupervisorControlWire.error(from: code) {
            case .refused(let reason): XCTAssertEqual(reason, "why")
            case .invalidArgument(let name): XCTAssertEqual(name, "nonce")
            default: break
            }
        }

        XCTAssertEqual(SupervisorControlWire.machServiceName(bundlePrefix: "se.stafe.executive-ai"), "se.stafe.executive-ai.supervisor.control")
        XCTAssertEqual(SupervisorControlWire.codeSigningRequirement(teamIdentifier: "ABCDE12345"), "anchor apple generic and certificate leaf[subject.OU] = \"ABCDE12345\"")
    }

    private final class ObservedKeys: @unchecked Sendable {
        private let lock = NSLock()
        private(set) var keys: [IpcKey] = []

        func append(_ key: IpcKey) {
            lock.withLock { keys.append(key) }
        }
    }

    private final class Counter: @unchecked Sendable {
        private let lock = NSLock()
        private(set) var value = 0

        func increment() {
            lock.withLock { value += 1 }
        }
    }
}
