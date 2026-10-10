// The client side of ipc-contract §3.2 step 4 and §8.2's single retries (threat model T-31,
// T-34): an expired or missing grant is replaced by one silent pairing; `session_invalid` and
// `signature_invalid` re-pair once and then surface; `timestamp_skew` resyncs the clock from
// `server_time` once; `nonce_replayed` retries once with a fresh nonce; "Reset security pairing"
// hands the fresh grant to the session; and without a Supervisor nothing goes on the wire at all.

import Foundation
import XCTest
@testable import LaravelExecutiveAI

final class IpcTokenClientTest: XCTestCase {
    private var healthBody: Data!

    override func setUpWithError() throws {
        try super.setUpWithError()
        IpcStubURLProtocol.reset()
        healthBody = try IpcTestSupport.successBody(data: try IpcTestSupport.healthFixtureData(.coreReadyNoAI))
    }

    override func tearDown() {
        IpcStubURLProtocol.reset()
        super.tearDown()
    }

    private let freshSessionId = "01M4G0R2Y3K8F6V1N7T5B9D4HA"

    private var freshGrant: PairingGrant {
        IpcTestSupport.grant(sessionId: freshSessionId)
    }

    /// Answers every panel operation for `sessionId`, and `401 session_invalid` (unsigned) for
    /// any other session.
    private func serverAccepting(sessionId: String) -> IpcStubURLProtocol.Handler {
        let accept = try! IpcTestSupport.panelServer(health: .coreReadyNoAI, signingKey: IpcTestSupport.signingKey(sessionId: sessionId))
        let invalid = try! IpcTestSupport.errorBody(code: "session_invalid")

        return { request, body in
            let token = IpcToken.parse(String((request.value(forHTTPHeaderField: "Authorization") ?? "").dropFirst("Bearer ".count)))

            guard token?.sessionId == sessionId else {
                return IpcTestSupport.unsignedReply(status: 401, body: invalid)
            }

            return accept(request, body)
        }
    }

    // MARK: - Expiry and absence

    func testAGrantExpiringWithin24HoursIsReplacedBeforeTheFirstRequest() async throws {
        let now = IpcTestSupport.fixtureNow
        let expiring = IpcTestSupport.grant(expiresAt: Int(now.timeIntervalSince1970) + 3600)
        let store = InMemorySessionStore(grant: expiring)
        let control = StubSupervisorControl(pair: [.success(freshGrant)])
        let session = IpcTestSupport.session(control: control, store: store, now: now)
        IpcStubURLProtocol.install(serverAccepting(sessionId: freshSessionId))

        _ = try await IpcTestSupport.client(session: session, now: now).health()

        XCTAssertEqual(control.pairCalls, 1)
        XCTAssertEqual(IpcStubURLProtocol.requests.count, 1)
        XCTAssertEqual(IpcToken.parse(String(IpcStubURLProtocol.requests[0].header("Authorization")!.dropFirst(7)))?.sessionId, freshSessionId)
        XCTAssertEqual(store.stored?.sessionId, freshSessionId, "the fresh grant is persisted")
    }

    func testAnExpiredGrantIsReplaced() async throws {
        let expired = IpcTestSupport.grant(expiresAt: Int(IpcTestSupport.fixtureNow.timeIntervalSince1970) - 10)
        let control = StubSupervisorControl(pair: [.success(freshGrant)])
        let session = IpcTestSupport.session(grant: expired, control: control)
        IpcStubURLProtocol.install(serverAccepting(sessionId: freshSessionId))

        _ = try await IpcTestSupport.client(session: session).health()

        XCTAssertEqual(control.pairCalls, 1)
    }

    func testAMissingGrantPairsOnceAndEveryLaterRequestReusesIt() async throws {
        let control = StubSupervisorControl(pair: [.success(freshGrant)])
        let store = InMemorySessionStore()
        let session = IpcTestSupport.session(control: control, store: store)
        IpcStubURLProtocol.install(serverAccepting(sessionId: freshSessionId))
        let client = IpcTestSupport.client(session: session)

        _ = try await client.health()
        _ = try await client.health()
        _ = try await client.heartbeat(panelOpen: false)

        XCTAssertEqual(control.pairCalls, 1)
        XCTAssertEqual(store.saves, 1)
        XCTAssertEqual(IpcStubURLProtocol.requests.count, 3)
    }

    func testAValidStoredGrantNeverTriggersPairing() async throws {
        let control = StubSupervisorControl()
        let session = IpcTestSupport.session(control: control)
        IpcStubURLProtocol.install(serverAccepting(sessionId: IpcTestSupport.fixtureSessionId))

        _ = try await IpcTestSupport.client(session: session).health()

        XCTAssertEqual(control.calls, [])
    }

    // MARK: - session_invalid / signature_invalid

    func testSessionInvalidRepairsOnceAndRetriesWithTheNewToken() async throws {
        let control = StubSupervisorControl(pair: [.success(freshGrant)])
        let store = InMemorySessionStore(grant: IpcTestSupport.grant())
        let session = IpcTestSupport.session(control: control, store: store)
        IpcStubURLProtocol.install(serverAccepting(sessionId: freshSessionId))

        let document = try await IpcTestSupport.client(session: session).health()

        XCTAssertTrue(document.ready)
        XCTAssertEqual(control.pairCalls, 1)
        let sessions = IpcStubURLProtocol.requests.map { IpcToken.parse(String($0.header("Authorization")!.dropFirst(7)))?.sessionId }
        XCTAssertEqual(sessions, [IpcTestSupport.fixtureSessionId, freshSessionId])
        XCTAssertEqual(store.stored?.sessionId, freshSessionId)
    }

    func testSessionInvalidTwiceSurfacesAfterExactlyOneRepair() async throws {
        let control = StubSupervisorControl(pair: [.success(freshGrant)])
        let session = IpcTestSupport.session(control: control)
        let invalid = try IpcTestSupport.errorBody(code: "session_invalid")
        IpcStubURLProtocol.install { _, _ in IpcTestSupport.unsignedReply(status: 401, body: invalid) }
        let client = IpcTestSupport.client(session: session)

        let result = await coreResult { try await client.health() }

        XCTAssertEqual(result.failureValue, .unreachable("the IPC session is invalid after re-pairing"))
        XCTAssertEqual(control.pairCalls, 1)
        XCTAssertEqual(IpcStubURLProtocol.requests.count, 2)

        // The next refresh starts over: one more pairing, two more requests — never a loop.
        _ = await coreResult { try await client.health() }
        XCTAssertEqual(control.pairCalls, 2)
        XCTAssertEqual(IpcStubURLProtocol.requests.count, 4)
    }

    func testSignatureInvalidRepairsOnce() async throws {
        let control = StubSupervisorControl(pair: [.success(freshGrant)])
        let session = IpcTestSupport.session(control: control)
        let rejected = try IpcTestSupport.errorBody(code: "signature_invalid")
        let body = healthBody!
        IpcStubURLProtocol.install { request, _ in
            let token = IpcToken.parse(String((request.value(forHTTPHeaderField: "Authorization") ?? "").dropFirst(7)))

            if token?.sessionId == IpcTestSupport.fixtureSessionId {
                return IpcTestSupport.signedReply(for: request, status: 401, body: rejected)
            }

            return IpcTestSupport.signedReply(for: request, status: 200, body: body, signingKey: IpcTestSupport.signingKey(sessionId: "01M4G0R2Y3K8F6V1N7T5B9D4HA"))
        }

        _ = try await IpcTestSupport.client(session: session).health()

        XCTAssertEqual(control.pairCalls, 1)
        XCTAssertEqual(IpcStubURLProtocol.requests.count, 2)
    }

    func testAGrantAlreadyRotatedIntoTheStoreIsUsedWithoutAskingTheSupervisor() async throws {
        // Another writer ("Reset security pairing" from a previous launch) stored a newer grant.
        let control = StubSupervisorControl()
        let store = InMemorySessionStore(grant: IpcTestSupport.grant())
        let session = IpcTestSupport.session(control: control, store: store)
        _ = try await session.currentGrant()
        try store.save(freshGrant)
        IpcStubURLProtocol.install(serverAccepting(sessionId: freshSessionId))

        _ = try await IpcTestSupport.client(session: session).health()

        XCTAssertEqual(control.pairCalls, 0)
        XCTAssertEqual(IpcStubURLProtocol.requests.count, 2)
    }

    func testResetPairingThroughServiceControlReplacesTheSessionGrantAndClearsTheCache() async throws {
        let directory = MenuBarFixtures.temporaryDirectory()
        let cache = BriefCache(directory: directory)
        try cache.store(document: try JSONValue.parse(try MenuBarFixtures.brief("brief-canonical")), etag: "\"01M4FJ7GY0PZH6YA3D9574XN5K:1\"")
        let control = StubSupervisorControl(pair: [.success(freshGrant)])
        let store = InMemorySessionStore(grant: IpcTestSupport.grant())
        let session = IpcTestSupport.session(control: control, store: store)
        _ = try await session.currentGrant()
        let serviceControl = SupervisorServiceControl(control: control, cache: cache, uninstaller: ScriptedUninstaller(), session: session)

        let grant = try await serviceControl.resetPairing()

        XCTAssertEqual(grant.sessionId, freshSessionId)
        XCTAssertEqual(control.calls, ["resetPairing", "pair"])
        XCTAssertEqual(store.stored?.sessionId, freshSessionId)
        XCTAssertNil(cache.load(), "no brief fetched under the old key survives the rotation")

        IpcStubURLProtocol.install(serverAccepting(sessionId: freshSessionId))
        _ = try await IpcTestSupport.client(session: session).health()
        XCTAssertEqual(IpcStubURLProtocol.requests.count, 1, "the fresh grant is used at once")
    }

    // MARK: - timestamp_skew / nonce_replayed

    func testTimestampSkewResyncsFromServerTimeOnce() async throws {
        let now = IpcTestSupport.fixtureNow
        let serverTime = now.addingTimeInterval(100)
        let skew = try IpcTestSupport.errorBody(code: "timestamp_skew", serverTime: ISO8601DateFormatter().string(from: serverTime))
        let body = healthBody!
        IpcStubURLProtocol.install { request, _ in
            let presented = Int(request.value(forHTTPHeaderField: "X-Lxai-Timestamp") ?? "") ?? 0

            if abs(presented - Int(serverTime.timeIntervalSince1970)) > 30 {
                return IpcTestSupport.signedReply(for: request, status: 401, body: skew)
            }

            return IpcTestSupport.signedReply(for: request, status: 200, body: body)
        }

        let client = IpcTestSupport.client(session: IpcTestSupport.session(), now: now)
        _ = try await client.health()

        let timestamps = IpcStubURLProtocol.requests.map { Int($0.header("X-Lxai-Timestamp") ?? "") }
        XCTAssertEqual(timestamps, [Int(now.timeIntervalSince1970), Int(serverTime.timeIntervalSince1970)])

        _ = try await client.health()
        XCTAssertEqual(IpcStubURLProtocol.requests.count, 3, "the offset is kept for later requests")
    }

    func testPersistentTimestampSkewSurfacesAfterOneRetry() async throws {
        let skew = try IpcTestSupport.errorBody(code: "timestamp_skew")
        IpcStubURLProtocol.install { request, _ in IpcTestSupport.signedReply(for: request, status: 401, body: skew) }

        let result = await ipcResult { try await IpcTestSupport.client(session: IpcTestSupport.session()).send(.health) }

        XCTAssertEqual(result.failureValue, .timestampSkew)
        XCTAssertEqual(IpcStubURLProtocol.requests.count, 2)
    }

    func testNonceReplayedRetriesOnceWithAFreshNonce() async throws {
        let replayed = try IpcTestSupport.errorBody(code: "nonce_replayed")
        let body = healthBody!
        let firstNonce = NonceSequence().next()
        IpcStubURLProtocol.install { request, _ in
            if request.value(forHTTPHeaderField: "X-Lxai-Nonce") == firstNonce {
                return IpcTestSupport.signedReply(for: request, status: 401, body: replayed)
            }

            return IpcTestSupport.signedReply(for: request, status: 200, body: body)
        }

        _ = try await IpcTestSupport.client(session: IpcTestSupport.session(), nonces: NonceSequence()).health()

        let nonces = IpcStubURLProtocol.requests.map { $0.header("X-Lxai-Nonce") }
        XCTAssertEqual(nonces.count, 2)
        XCTAssertNotEqual(nonces[0], nonces[1])
        XCTAssertEqual(Set(nonces.compactMap { $0?.count }), [22])
    }

    func testEveryRequestCarriesAFreshNonceAndARequestId() async throws {
        let body = healthBody!
        IpcStubURLProtocol.install { request, _ in IpcTestSupport.signedReply(for: request, status: 200, body: body) }
        let client = IpcTestSupport.client(session: IpcTestSupport.session())

        for _ in 0..<5 {
            _ = try await client.health()
        }

        let nonces = IpcStubURLProtocol.requests.compactMap { $0.header("X-Lxai-Nonce") }
        let requestIds = IpcStubURLProtocol.requests.compactMap { $0.header("X-Lxai-Request-Id") }
        XCTAssertEqual(Set(nonces).count, 5)
        XCTAssertEqual(Set(requestIds).count, 5)
        XCTAssertTrue(requestIds.allSatisfy(ULID.isValid))
    }

    // MARK: - No Supervisor

    @MainActor
    func testWithoutASupervisorNothingGoesOnTheWireAndThePanelSeesUnreachable() async throws {
        let control = StubSupervisorControl(pair: [.failure(.unreachable("no listener"))])
        let session = IpcTestSupport.session(grant: nil, control: control)
        let body = healthBody!
        IpcStubURLProtocol.install { request, _ in IpcTestSupport.signedReply(for: request, status: 200, body: body) }
        let client = IpcTestSupport.client(session: session)

        do {
            _ = try await client.health()
            XCTFail("expected unreachable")
        } catch {
            XCTAssertEqual(error as? CoreClientError, .unreachable("the Supervisor did not pair the menu bar: the Supervisor did not answer"))
        }

        XCTAssertEqual(IpcStubURLProtocol.requests.count, 0)

        let model = PanelModel(client: client, cache: BriefCache(directory: MenuBarFixtures.temporaryDirectory()), ports: SpyPorts().ports)
        await model.refresh()
        XCTAssertEqual(model.state, .coreUnreachable)
    }

    func testARefusedPairingSurfacesItsSanitisedReason() async throws {
        let control = StubSupervisorControl(pair: [.failure(.refused("no live session"))])
        let session = IpcTestSupport.session(grant: nil, control: control)

        let result = await ipcResult { try await IpcTestSupport.client(session: session).send(.health) }

        XCTAssertEqual(result.failureValue, .supervisorUnreachable("no live session"))
    }

    func testTheFactoryReturnsAnUnreachableClientUntilTheCheckpointNamesAPort() throws {
        let session = IpcTestSupport.session()
        let empty = InMemoryInstallCheckpointStore()

        XCTAssertTrue(IpcClientFactory.makeCoreClient(checkpointStore: empty, session: session) is UnreachableCoreClient)

        var checkpoint = InstallCheckpoint(updatedAt: Date())
        checkpoint.preflight = InstallCheckpoint.PreflightSummary(port: 8792, language: .en, existingInstall: .fresh, warnings: [], fileVaultEnabled: nil)
        let live = IpcClientFactory.makeCoreClient(checkpointStore: InMemoryInstallCheckpointStore(checkpoint: checkpoint), session: session)

        XCTAssertTrue(live is LoopbackIpcClient)
        XCTAssertEqual(live.origin.absoluteString, "http://127.0.0.1:8792")
        XCTAssertEqual((live as? LoopbackIpcClient)?.configuration.since.hasSuffix("Z"), true)
    }
}
