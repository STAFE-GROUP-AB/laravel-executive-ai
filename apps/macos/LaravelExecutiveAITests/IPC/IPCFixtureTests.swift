// ipc-contract §12 `IPCFixtureTests`: for every `packaging/fixtures/ipc/*.json`, the client built
// with the test key and the fixture's session puts exactly the recorded request on the wire —
// same headers, same canonical body bytes, identical signature — and accepts or rejects the
// recorded answer exactly as the contract says. A recording tampered with in status, body or
// nonce is refused before its body is read.

import Foundation
import XCTest
@testable import LaravelExecutiveAI

final class IPCFixtureTests: XCTestCase {
    override func setUp() {
        super.setUp()
        IpcStubURLProtocol.reset()
    }

    override func tearDown() {
        IpcStubURLProtocol.reset()
        super.tearDown()
    }

    func testEveryFixtureRequestIsReproducedByteForByteAndItsAnswerHandledAsTheContractSays() async throws {
        var verified = 0

        for fixture in try IpcFixture.all() {
            try await assertFixture(fixture)
            verified += 1
        }

        XCTAssertGreaterThanOrEqual(verified, 16)
    }

    func testTheHeartbeatOperationBuilderReproducesTheRecordedBody() async throws {
        let fixture = try IpcFixture.load("heartbeat")
        let operation = IpcOperation.heartbeat(clientVersion: "1.0.0 (120)", since: "2026-10-10T17:30:00+02:00", panelOpen: false)

        XCTAssertEqual(try operation.bodyBytes(), try fixture.requestBodyBytes())
        XCTAssertEqual(try IpcOperation.aiPause.bodyBytes(), try IpcFixture.load("ai-pause").requestBodyBytes())
        XCTAssertEqual(try IpcOperation.aiResume.bodyBytes(), try IpcFixture.load("ai-resume").requestBodyBytes())
        XCTAssertEqual(IpcOperation.pendingApprovals.pathAndQuery, try IpcFixture.load("approvals-pending").path)

        let owner = IpcOperation.createOwner(OwnerDraft(name: "Ada Owner", email: "ada.owner@example.test"), timeZone: "Europe/Stockholm", language: "sv")
        XCTAssertEqual(try owner.bodyBytes(), try IpcFixture.load("owner").requestBodyBytes())
        XCTAssertEqual(owner.pathAndQuery, try IpcFixture.load("owner").path)
        XCTAssertEqual(IpcOperation.contractVersion, "1.1.0", "§5.1: the minor that added owner.create")

        let quitting = IpcOperation.heartbeat(clientVersion: "1.0.0 (120)", since: "2026-10-10T17:30:00+02:00", panelOpen: false, quitting: true)
        XCTAssertTrue(String(decoding: try quitting.bodyBytes(), as: UTF8.self).contains("\"quitting\":true"))
    }

    func testTamperedRecordingsAreRefusedBeforeTheBodyIsRead() async throws {
        for fixture in try IpcFixture.all() where fixture.responseHeaders["X-Lxai-Signature"] != nil {
            let body = try fixture.responseBodyBytes()
            let variants: [(String, IpcStubURLProtocol.Reply)] = [
                ("status", .response(status: fixture.responseStatus == 200 ? 503 : 200, headers: fixture.responseHeaders, body: body)),
                ("body", .response(status: fixture.responseStatus, headers: fixture.responseHeaders, body: body + Data(" ".utf8))),
                ("nonce", .response(status: fixture.responseStatus, headers: fixture.responseHeaders.merging(["X-Lxai-Signature": IpcSigning.signResponse(signingKey: fixture.signingKey, status: fixture.responseStatus, nonce: "AAAAAAAAAAAAAAAAAAAAAA", body: body)]) { _, new in new }, body: body)),
                ("missing", .response(status: fixture.responseStatus, headers: fixture.responseHeaders.filter { $0.key != "X-Lxai-Signature" }, body: body)),
            ]

            for (label, reply) in variants {
                let client = try makeClient(for: fixture)
                IpcStubURLProtocol.install { _, _ in reply }

                let result = await ipcResult { try await client.send(fixture.ipcOperation()) }

                guard case .unreachable = result.failureValue else {
                    XCTFail("\(fixture.name) tampered \(label): expected unreachable, got \(String(describing: result))")
                    continue
                }
            }
        }
    }

    // MARK: - One fixture

    private func makeClient(for fixture: IpcFixture, control: StubSupervisorControl? = nil) throws -> LoopbackIpcClient {
        let token = fixture.token
        let grant = IpcTestSupport.grant(client: token.client, sessionId: token.sessionId, expiresAt: token.expiresAt)
        let session = IpcTestSupport.session(grant: grant, control: control ?? StubSupervisorControl(pair: [.success(grant)]), now: fixture.date)

        return IpcTestSupport.client(session: session, now: fixture.date, nonce: fixture.nonce, requestId: fixture.requestId, wireMajor: fixture.wireMajor)
    }

    private func assertFixture(_ fixture: IpcFixture) async throws {
        IpcStubURLProtocol.reset()
        let control = StubSupervisorControl(pair: [.success(IpcTestSupport.grant(client: fixture.token.client, sessionId: fixture.token.sessionId, expiresAt: fixture.token.expiresAt))])
        let client = try makeClient(for: fixture, control: control)
        let reply = try fixture.recordedReply()
        IpcStubURLProtocol.install { _, _ in reply }

        let result = await ipcResult { try await client.send(fixture.ipcOperation()) }
        let requests = IpcStubURLProtocol.requests
        let first = try XCTUnwrap(requests.first, fixture.name)

        // Request: method, target, canonical body, every recorded header.
        XCTAssertEqual(first.request.httpMethod, fixture.method, fixture.name)
        XCTAssertEqual(first.request.url?.absoluteString, "http://127.0.0.1:8787\(fixture.path)", fixture.name)
        XCTAssertEqual(first.body, try fixture.requestBodyBytes(), "\(fixture.name): body bytes")
        IpcHeaderRules.assertClean(first.request, fixture.name)

        for (name, value) in fixture.requestHeaders where name != "Host" {
            if name == "X-Lxai-Signature", fixture.errorCode == "signature_invalid" {
                // The one recording whose request signature is wrong on purpose.
                XCTAssertNotEqual(first.header(name), value, "\(fixture.name): the client must not reproduce a bad signature")
                continue
            }

            XCTAssertEqual(first.header(name), value, "\(fixture.name): \(name)")
        }

        let sentLxai = Set((first.request.allHTTPHeaderFields ?? [:]).keys.filter { $0.hasPrefix("X-Lxai-") })
        let recordedLxai = Set(fixture.requestHeaders.keys.filter { $0.hasPrefix("X-Lxai-") })
        XCTAssertEqual(sentLxai, recordedLxai, "\(fixture.name): exactly the recorded X-Lxai headers")
        XCTAssertEqual(first.header("Content-Type"), fixture.requestHeaders["Content-Type"], "\(fixture.name): Content-Type only with a body")
        XCTAssertEqual(first.header("If-None-Match"), fixture.requestHeaders["If-None-Match"], fixture.name)

        // Answer: exactly what the §8.2 table says.
        switch fixture.errorCode {
        case nil:
            let response = try XCTUnwrap(result.successValue, "\(fixture.name): \(String(describing: result.failureValue))")
            XCTAssertEqual(response.status, fixture.responseStatus, fixture.name)
            XCTAssertTrue(response.signed, fixture.name)
            XCTAssertEqual(requests.count, 1, fixture.name)

            if fixture.responseStatus == 304 {
                XCTAssertTrue(response.body.isEmpty, fixture.name)
                XCTAssertEqual(response.header("ETag"), fixture.responseHeaders["ETag"], fixture.name)
            } else {
                XCTAssertEqual(try response.envelopeData(), fixture.responseBody?["data"], "\(fixture.name): data")
            }

            if fixture.name == "health-not-ready" {
                XCTAssertEqual(response.status, 503)
                XCTAssertEqual(try response.envelopeData()["ready"]?.boolValue, false)
            }
        case "forbidden_scope":
            XCTAssertEqual(result.failureValue, .forbiddenScope, fixture.name)
            XCTAssertEqual(requests.count, 1, fixture.name)
        case "rate_limited":
            XCTAssertEqual(result.failureValue, .rateLimited(retryAfterSeconds: 10), fixture.name)
            XCTAssertEqual(requests.count, 1, fixture.name)
        case "signature_invalid":
            // §8.2: re-pair, retry once; a second rejection surfaces.
            XCTAssertEqual(result.failureValue, .signatureInvalid, fixture.name)
            XCTAssertEqual(requests.count, 2, fixture.name)
            XCTAssertEqual(control.pairCalls, 1, fixture.name)
        case "owner_exists":
            // §6.9: never retried, and the installer reads it as a hand-off
            // (IpcOwnerCreatorTests), not as a failure.
            XCTAssertEqual(result.failureValue, .ownerExists, fixture.name)
            XCTAssertEqual(requests.count, 1, fixture.name)
        case "version_unsupported":
            XCTAssertEqual(result.failureValue, .versionUnsupported(serverMajor: 1, supportedVersions: [1], hint: "Update or repair Laravel Executive AI (Settings → Updates → Repair)."), fixture.name)
            XCTAssertEqual(requests.count, 1, fixture.name)
        case let other:
            XCTFail("\(fixture.name): no expectation for error code \(other ?? "nil") — a new fixture needs a row here")
        }

        XCTAssertEqual(control.calls.filter { $0 != "pair" }, [], "\(fixture.name): nothing but pairing reaches the Supervisor")
    }
}
