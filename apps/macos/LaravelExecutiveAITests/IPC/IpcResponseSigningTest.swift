// ipc-contract §12 `IpcResponseSigningTest` (threat model T-32): a tampered body, a tampered
// status, a wrong nonce or a missing signature each end in "Core unreachable" and the body is
// never data. The four unsigned answers the server sends before it has a key are acted on —
// re-pair, diagnostics, "Update required", the broker hint — and nothing else may arrive unsigned.

import Foundation
import XCTest
@testable import LaravelExecutiveAI

final class IpcResponseSigningTest: XCTestCase {
    private var healthData: JSONValue!

    override func setUpWithError() throws {
        try super.setUpWithError()
        IpcStubURLProtocol.reset()
        healthData = try IpcTestSupport.healthFixtureData(.aiReady)
    }

    override func tearDown() {
        IpcStubURLProtocol.reset()
        super.tearDown()
    }

    private func makeClient(control: StubSupervisorControl = StubSupervisorControl(), sink: any IpcEventSink = NoopIpcSink()) -> LoopbackIpcClient {
        IpcTestSupport.client(session: IpcTestSupport.session(control: control), sink: sink)
    }

    // MARK: - Tampering

    func testAValidSignedHealthAnswerIsAccepted() async throws {
        let body = try IpcTestSupport.successBody(data: healthData)
        IpcStubURLProtocol.install { request, _ in IpcTestSupport.signedReply(for: request, status: 200, body: body) }

        let document = try await makeClient().health()

        XCTAssertTrue(document.ready)
        XCTAssertEqual(document.pendingApprovals, 2)
    }

    func testATamperedBodyWithAValidLookingEnvelopeIsUnreachableNotData() async throws {
        let body = try IpcTestSupport.successBody(data: healthData)
        var forged = try XCTUnwrap(healthData.objectValue)
        forged["ready"] = .bool(true)
        forged["pending_approvals"] = .number(0)
        let forgedBody = try IpcTestSupport.successBody(data: .object(forged))

        IpcStubURLProtocol.install { request, _ in
            // Signature computed over the honest body, delivered with a different one.
            guard case .response(let status, let headers, _) = IpcTestSupport.signedReply(for: request, status: 200, body: body) else {
                return .failure(.unknown)
            }

            return .response(status: status, headers: headers, body: forgedBody)
        }

        let result = await coreResult { try await makeClient().health() }
        XCTAssertEqual(result.failureValue, .unreachable("the response signature did not verify"))
    }

    func testATamperedStatusIsUnreachable() async throws {
        let body = try IpcTestSupport.successBody(data: healthData)

        IpcStubURLProtocol.install { request, _ in
            guard case .response(_, let headers, let data) = IpcTestSupport.signedReply(for: request, status: 503, body: body) else {
                return .failure(.unknown)
            }

            return .response(status: 200, headers: headers, body: data)
        }

        let result = await coreResult { try await makeClient().health() }
        XCTAssertEqual(result.failureValue, .unreachable("the response signature did not verify"))
    }

    func testASignatureOverAnotherNonceIsUnreachable() async throws {
        let body = try IpcTestSupport.successBody(data: healthData)

        IpcStubURLProtocol.install { _, _ in
            .response(status: 200, headers: ["X-Lxai-Ipc-Version": "1", "X-Lxai-Signature": IpcSigning.signResponse(signingKey: IpcTestSupport.signingKey(), status: 200, nonce: "AAAAAAAAAAAAAAAAAAAAAA", body: body)], body: body)
        }

        let result = await coreResult { try await makeClient().health() }
        XCTAssertEqual(result.failureValue, .unreachable("the response signature did not verify"))
    }

    func testAMissingSignatureOnASuccessIsUnreachable() async throws {
        let body = try IpcTestSupport.successBody(data: healthData)
        IpcStubURLProtocol.install { _, _ in IpcTestSupport.unsignedReply(status: 200, body: body) }

        let result = await coreResult { try await makeClient().health() }
        XCTAssertEqual(result.failureValue, .unreachable("the response carried no signature"))
    }

    func testASignatureUnderAnotherSessionKeyIsUnreachable() async throws {
        let body = try IpcTestSupport.successBody(data: healthData)
        let otherKey = IpcTestSupport.signingKey(sessionId: "01M4G0R2Y3K8F6V1N7T5B9D4HA")
        IpcStubURLProtocol.install { request, _ in IpcTestSupport.signedReply(for: request, status: 200, body: body, signingKey: otherKey) }

        let result = await coreResult { try await makeClient().health() }
        XCTAssertEqual(result.failureValue, .unreachable("the response signature did not verify"))
    }

    func testTamperingIsCaughtOnEveryCoreClientOperation() async throws {
        let body = try IpcTestSupport.successBody(data: .object(["anything": .string("x")]))
        IpcStubURLProtocol.install { _, _ in IpcTestSupport.unsignedReply(status: 200, body: body) }
        let client = makeClient()

        let outcomes: [Result<String, CoreClientError>] = [
            await coreResult { String(describing: try await client.latestBrief(ifNoneMatch: nil)) },
            await coreResult { String(describing: try await client.pendingApprovals()) },
            await coreResult { String(describing: try await client.pauseAI()) },
            await coreResult { String(describing: try await client.resumeAI()) },
            await coreResult { String(describing: try await client.heartbeat(panelOpen: true)) },
        ]

        for outcome in outcomes {
            XCTAssertEqual(outcome.failureValue, .unreachable("the response carried no signature"))
        }
    }

    // MARK: - The unsigned answers the contract allows (§4.5)

    func testUnsigned401SessionInvalidRepairsOnceThenSurfaces() async throws {
        let control = StubSupervisorControl()
        let body = try IpcTestSupport.errorBody(code: "session_invalid")
        IpcStubURLProtocol.install { _, _ in IpcTestSupport.unsignedReply(status: 401, body: body) }

        let result = await ipcResult { try await makeClient(control: control).send(.health) }

        XCTAssertEqual(result.failureValue, .sessionInvalid)
        XCTAssertEqual(control.pairCalls, 1)
        XCTAssertEqual(IpcStubURLProtocol.requests.count, 2)
    }

    func testUnsigned426CarriesTheRepairHintAndFailsClosed() async throws {
        let body = try IpcTestSupport.errorBody(code: "version_unsupported", message: "This IPC version is not supported by the installed core.", hint: "Update or repair Laravel Executive AI (Settings → Updates → Repair).", supportedVersions: [2])
        IpcStubURLProtocol.install { _, _ in IpcTestSupport.unsignedReply(status: 426, body: body) }

        let result = await ipcResult { try await makeClient().send(.health) }

        XCTAssertEqual(result.failureValue, .versionUnsupported(serverMajor: 2, supportedVersions: [2], hint: "Update or repair Laravel Executive AI (Settings → Updates → Repair)."))
        XCTAssertEqual(IpcStubURLProtocol.requests.count, 1, "never retried, never degraded")

        let core = await coreResult { try await makeClient().health() }
        XCTAssertEqual(core.failureValue, .versionUnsupported(serverMajor: 2))
    }

    func testAResponseWireMajorAboveThisBuildIsUpdateRequiredEvenWhenSigned() async throws {
        let body = try IpcTestSupport.successBody(data: healthData)
        IpcStubURLProtocol.install { request, _ in IpcTestSupport.signedReply(for: request, status: 200, body: body, extraHeaders: ["X-Lxai-Ipc-Version": "2"]) }

        let result = await coreResult { try await makeClient().health() }

        XCTAssertEqual(result.failureValue, .versionUnsupported(serverMajor: 2))
    }

    func testUnsigned403OriginRejectedSurfacesForDiagnosticsWithoutRetry() async throws {
        let body = try IpcTestSupport.errorBody(code: "origin_rejected")
        IpcStubURLProtocol.install { _, _ in IpcTestSupport.unsignedReply(status: 403, body: body) }

        let result = await ipcResult { try await makeClient().send(.health) }

        XCTAssertEqual(result.failureValue, .originRejected)
        XCTAssertEqual(IpcStubURLProtocol.requests.count, 1)
        XCTAssertEqual(result.failureValue?.coreClientError, .forbidden)
    }

    func testUnsigned503SecretsUnavailableIsCoreUnreachableWithTheBrokerHint() async throws {
        let body = try IpcTestSupport.errorBody(code: "secrets_unavailable", hint: "Unlock or restart the Supervisor.", retryAfterSeconds: 5)
        IpcStubURLProtocol.install { _, _ in IpcTestSupport.unsignedReply(status: 503, body: body, extraHeaders: ["Retry-After": "5"]) }

        let result = await ipcResult { try await makeClient().send(.health) }

        XCTAssertEqual(result.failureValue, .secretsUnavailable(hint: "Unlock or restart the Supervisor.", retryAfterSeconds: 5))
        XCTAssertEqual(result.failureValue?.coreClientError, .unreachable("the core could not read its pairing key"))
    }

    func testAnyOtherUnsignedErrorIsUnreachable() async throws {
        for (status, code) in [(403, "forbidden_scope"), (401, "signature_invalid"), (401, "timestamp_skew"), (429, "rate_limited"), (404, "not_found"), (500, "internal")] {
            let body = try IpcTestSupport.errorBody(code: code)
            IpcStubURLProtocol.install { _, _ in IpcTestSupport.unsignedReply(status: status, body: body) }

            let result = await ipcResult { try await makeClient().send(.health) }

            XCTAssertEqual(result.failureValue, .unreachable("the response carried no signature"), "\(status) \(code)")
        }
    }

    func testAnUnsignedAnswerWithAnAllowedStatusButASuccessBodyIsUnreachable() async throws {
        let body = try IpcTestSupport.successBody(data: healthData)
        IpcStubURLProtocol.install { _, _ in IpcTestSupport.unsignedReply(status: 401, body: body) }

        let result = await ipcResult { try await makeClient().send(.health) }

        XCTAssertEqual(result.failureValue, .unreachable("the response carried no signature"))
    }

    func testASigned304IsAcceptedOverTheEmptyBodyHash() async throws {
        IpcStubURLProtocol.install { request, _ in IpcTestSupport.signedReply(for: request, status: 304, body: Data(), extraHeaders: ["ETag": "\"01M4FJ7GY0PZH6YA3D9574XN5K:1\""]) }

        let answer = try await makeClient().latestBrief(ifNoneMatch: "\"01M4FJ7GY0PZH6YA3D9574XN5K:1\"")

        XCTAssertEqual(answer, .notModified(etag: "\"01M4FJ7GY0PZH6YA3D9574XN5K:1\""))
        XCTAssertEqual(IpcStubURLProtocol.requests.first?.header("If-None-Match"), "\"01M4FJ7GY0PZH6YA3D9574XN5K:1\"")
    }

    func testASigned404OnBriefsLatestIsNotFound() async throws {
        let body = try IpcTestSupport.errorBody(code: "not_found")
        IpcStubURLProtocol.install { request, _ in IpcTestSupport.signedReply(for: request, status: 404, body: body) }

        let result = await coreResult { try await makeClient().latestBrief(ifNoneMatch: nil) }

        XCTAssertEqual(result.failureValue, .notFound)
    }

    func testASignedPolicyDenialOnResumeIsForbidden() async throws {
        let body = try IpcTestSupport.errorBody(code: "policy_denied", message: "The automation broker is locked.")
        IpcStubURLProtocol.install { request, _ in IpcTestSupport.signedReply(for: request, status: 403, body: body) }

        let result = await coreResult { try await makeClient().resumeAI() }

        XCTAssertEqual(result.failureValue, .forbidden)
    }

    func testAConnectionFailureIsUnreachable() async throws {
        IpcStubURLProtocol.install { _, _ in .failure(.cannotConnectToHost) }

        let result = await coreResult { try await makeClient().health() }

        XCTAssertEqual(result.failureValue, .unreachable("no answer on loopback"))
    }

    func testASignedAnswerThatIsNotAnEnvelopeIsUnreachable() async throws {
        let body = Data("<html>not json</html>".utf8)
        IpcStubURLProtocol.install { request, _ in IpcTestSupport.signedReply(for: request, status: 200, body: body) }

        let result = await coreResult { try await makeClient().health() }

        XCTAssertEqual(result.failureValue, .unreachable("the answer is not the IPC success envelope"))
    }
}
