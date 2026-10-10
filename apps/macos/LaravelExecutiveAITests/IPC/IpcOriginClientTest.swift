// The client's half of ipc-contract §1.1, §5.2 and §9 (threat model T-17, T-20): every request
// goes to the literal `127.0.0.1:<port>` and nowhere else; no cookies, no proxy, no cache, no
// connectivity waiting; a 3xx is an error and is never followed; no browser or proxy header is
// ever sent; a grant for another port is refused before anything goes on the wire.

import Foundation
import XCTest
@testable import LaravelExecutiveAI

final class IpcOriginClientTest: XCTestCase {
    private var healthBody: Data!

    override func setUpWithError() throws {
        try super.setUpWithError()
        IpcStubURLProtocol.reset()
        healthBody = try IpcTestSupport.successBody(data: try IpcTestSupport.healthFixtureData(.aiReady))
    }

    override func tearDown() {
        IpcStubURLProtocol.reset()
        super.tearDown()
    }

    func testEveryRequestTargetsTheLiteralLoopbackAddressOnTheGrantsPort() async throws {
        IpcStubURLProtocol.install(try IpcTestSupport.panelServer(port: 8792))
        let client = IpcTestSupport.client(port: 8792, session: IpcTestSupport.session(grant: IpcTestSupport.grant(port: 8792)))

        _ = try await client.health()
        _ = try await client.heartbeat(panelOpen: true)
        _ = await coreResult { try await client.latestBrief(ifNoneMatch: nil) }

        XCTAssertEqual(client.origin.absoluteString, "http://127.0.0.1:8792")

        for recorded in IpcStubURLProtocol.requests {
            let url = try XCTUnwrap(recorded.request.url)
            XCTAssertEqual(url.scheme, "http")
            XCTAssertEqual(url.host(), "127.0.0.1")
            XCTAssertEqual(url.port, 8792)
            XCTAssertEqual("\(url.host()!):\(url.port!)", "127.0.0.1:8792", "the Host the session derives is exactly the §5.2 value")
            XCTAssertFalse(url.absoluteString.contains("localhost"))
        }

        XCTAssertEqual(IpcStubURLProtocol.requests.count, 3)
    }

    func testTheSessionConfigurationHasNoProxyNoCookiesNoCacheAndNoConnectivityWaiting() {
        let configuration = LoopbackIpcClient.makeURLSessionConfiguration()

        XCTAssertEqual(configuration.connectionProxyDictionary?.isEmpty, true, "connectionProxyDictionary = [:] (§9)")
        XCTAssertFalse(configuration.waitsForConnectivity)
        XCTAssertFalse(configuration.httpShouldSetCookies)
        XCTAssertEqual(configuration.httpCookieAcceptPolicy, .never)
        XCTAssertNil(configuration.httpCookieStorage)
        XCTAssertNil(configuration.urlCache)
        XCTAssertEqual(configuration.requestCachePolicy, .reloadIgnoringLocalCacheData)
        XCTAssertFalse(configuration.allowsCellularAccess)
        XCTAssertFalse(configuration.allowsExpensiveNetworkAccess)
        XCTAssertFalse(configuration.allowsConstrainedNetworkAccess)
        XCTAssertEqual(configuration.httpMaximumConnectionsPerHost, 1, "one keep-alive connection (§9)")
        XCTAssertLessThanOrEqual(configuration.timeoutIntervalForRequest, 35)
        XCTAssertLessThanOrEqual(configuration.timeoutIntervalForResource, 35)
    }

    func testPerOperationTimeoutsFollowTheContractTable() async throws {
        IpcStubURLProtocol.install(try IpcTestSupport.panelServer())
        let client = IpcTestSupport.client(session: IpcTestSupport.session())

        _ = try await client.health()
        _ = try await client.heartbeat(panelOpen: false)
        _ = try await client.pendingApprovals()

        XCTAssertEqual(IpcStubURLProtocol.requests.map(\.request.timeoutInterval), [6, 3, 5])
        XCTAssertTrue(IpcStubURLProtocol.requests.allSatisfy { !$0.request.httpShouldHandleCookies })
        XCTAssertTrue(IpcStubURLProtocol.requests.allSatisfy { $0.request.cachePolicy == .reloadIgnoringLocalCacheData })
    }

    func testNoBrowserOrProxyHeaderIsEverSentAndOnlyAllowedLxaiHeadersAppear() async throws {
        IpcStubURLProtocol.install(try IpcTestSupport.panelServer())
        let client = IpcTestSupport.client(session: IpcTestSupport.session())

        _ = try await client.health()
        _ = try await client.heartbeat(panelOpen: true)
        _ = try await client.pauseAI()
        let missing = await coreResult { try await client.latestBrief(ifNoneMatch: "\"x:1\"") }
        XCTAssertEqual(missing.failureValue, .notFound)

        XCTAssertEqual(IpcStubURLProtocol.requests.count, 4)

        for recorded in IpcStubURLProtocol.requests {
            IpcHeaderRules.assertClean(recorded.request, recorded.request.url?.path ?? "")
            XCTAssertEqual(recorded.header("Accept"), "application/json")
            XCTAssertEqual(recorded.header("X-Lxai-Ipc-Version"), "1")
            XCTAssertTrue(recorded.header("Authorization")?.hasPrefix("Bearer lxai1.menubar.") == true)
            XCTAssertEqual(recorded.header("X-Lxai-Nonce")?.count, 22)
            XCTAssertTrue(recorded.header("X-Lxai-Signature")?.hasPrefix("v1=") == true)
            XCTAssertEqual(recorded.header("X-Lxai-Signature")?.count, 67)

            if recorded.body.isEmpty {
                XCTAssertNil(recorded.header("Content-Type"), "no Content-Type without a body")
            } else {
                XCTAssertEqual(recorded.header("Content-Type"), "application/json")
            }
        }
    }

    func testARedirectIsAnErrorAndIsNeverFollowed() async throws {
        IpcStubURLProtocol.install { _, _ in
            .response(status: 302, headers: ["Location": "http://localhost:8787/ipc/v1/health", "X-Lxai-Ipc-Version": "1"], body: Data())
        }
        let client = IpcTestSupport.client(session: IpcTestSupport.session())

        let result = await coreResult { try await client.health() }

        XCTAssertEqual(result.failureValue, .unreachable("the response carried no signature"))
        XCTAssertEqual(IpcStubURLProtocol.requests.count, 1, "the Location was not requested")
        XCTAssertEqual(IpcStubURLProtocol.requests.first?.request.url?.host(), "127.0.0.1")
    }

    func testASignedRedirectIsStillAnError() async throws {
        IpcStubURLProtocol.install { request, _ in
            IpcTestSupport.signedReply(for: request, status: 307, body: Data(), extraHeaders: ["Location": "http://127.0.0.1:8787/other"])
        }
        let client = IpcTestSupport.client(session: IpcTestSupport.session())

        let result = await ipcResult { try await client.send(.health) }

        XCTAssertEqual(result.failureValue, .redirected(status: 307))
        XCTAssertEqual(result.failureValue?.coreClientError, .unreachable("the core answered with a redirect (307)"))
        XCTAssertEqual(IpcStubURLProtocol.requests.count, 1)
    }

    func testAGrantForAnotherPortIsRefusedBeforeAnythingIsSent() async throws {
        let body = healthBody!
        IpcStubURLProtocol.install { request, _ in IpcTestSupport.signedReply(for: request, status: 200, body: body) }
        let client = IpcTestSupport.client(port: 8787, session: IpcTestSupport.session(grant: IpcTestSupport.grant(port: 8788)))

        let result = await ipcResult { try await client.send(.health) }

        XCTAssertEqual(result.failureValue, .unreachable("the pairing grant names another port"))
        XCTAssertEqual(IpcStubURLProtocol.requests.count, 0)
    }

    func testTheHealthLiveProbeIsPlainAndUnsigned() async throws {
        IpcStubURLProtocol.install { request, _ in
            guard request.url?.path == "/health/live" else {
                return .failure(.badURL)
            }

            return .response(status: 200, headers: ["Content-Type": "application/json"], body: Data("{\"status\":\"ok\"}".utf8))
        }
        let client = IpcTestSupport.client(session: IpcTestSupport.session(grant: nil, control: StubSupervisorControl(pair: [.failure(.unreachable("none"))])))

        let alive = await client.live()

        XCTAssertTrue(alive)
        let recorded = try XCTUnwrap(IpcStubURLProtocol.requests.first)
        XCTAssertEqual(recorded.request.url?.absoluteString, "http://127.0.0.1:8787/health/live")
        XCTAssertNil(recorded.header("Authorization"))
        XCTAssertNil(recorded.header("X-Lxai-Ipc-Version"))
        XCTAssertNil(recorded.header("X-Lxai-Signature"))

        IpcStubURLProtocol.install { _, _ in .response(status: 503, headers: [:], body: Data("{\"status\":\"ok\"}".utf8)) }
        let down = await client.live()
        XCTAssertFalse(down)
    }

    func testTheClientSourcesNeverNameLocalhostAsAnAddress() throws {
        let directory = TestFiles.repositoryRoot.appending(path: "apps/macos/LaravelExecutiveAI/IPC")
        let files = try FileManager.default.contentsOfDirectory(atPath: directory.path).filter { $0.hasSuffix(".swift") }
        XCTAssertGreaterThanOrEqual(files.count, 5)

        for file in files {
            let source = try String(contentsOf: directory.appending(path: file), encoding: .utf8)
            XCTAssertFalse(source.contains("://localhost"), "\(file) builds a URL on localhost")
            XCTAssertFalse(source.contains("localhost:"), "\(file) names localhost as a host")
            XCTAssertFalse(source.contains("[::1]"), "\(file) names the IPv6 loopback, which the core does not bind")
            XCTAssertFalse(source.contains("print("), "\(file) prints; the client logs through its sink only")
            XCTAssertFalse(source.contains("NSLog("), "\(file) logs outside the sink")
        }
    }

    func testServiceStateNamesMapToTheContractsVocabulary() {
        XCTAssertEqual(ServiceState.healthy.ipcContractName, "running")
        XCTAssertEqual(ServiceState.starting.ipcContractName, "starting")
        XCTAssertEqual(ServiceState.stopped.ipcContractName, "stopped")
        XCTAssertEqual(ServiceState.degraded.ipcContractName, "degraded")
        XCTAssertEqual(ServiceState.failed.ipcContractName, "failed")
    }
}
