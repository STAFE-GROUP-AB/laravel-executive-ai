// ipc-contract §10 on the Swift side (ADR-003 D7, threat model T-39): nothing the client logs and
// nothing it says in an error carries the token, the signing key, an elevation value or any
// entry of the secrets corpus — across successes, every error class and a body that itself
// contains secrets (the OAuth callback's `code`).

import Foundation
import XCTest
@testable import LaravelExecutiveAI

final class IpcRedactionClientTests: XCTestCase {
    override func setUp() {
        super.setUp()
        IpcStubURLProtocol.reset()
    }

    override func tearDown() {
        IpcStubURLProtocol.reset()
        super.tearDown()
    }

    func testLogLinesAndErrorDescriptionsNeverCarryATokenAKeyAnElevationOrACorpusValue() async throws {
        let grant = IpcTestSupport.grant()
        let elevation = IpcSigning.elevation(key: IpcKey.fixtureKey, sessionId: grant.sessionId, operation: "remote-access-enable", nonce: "cmVtb3RlLWFjY2Vzcy1lbg", expiresAt: 1_791_648_180)
        let corpus = try ServicesTestSupport.secretsCorpusValues()
        let sink = CapturingIpcSink()
        let control = StubSupervisorControl(pair: [.success(grant)])
        let client = IpcTestSupport.client(session: IpcTestSupport.session(grant: grant, control: control), sink: sink)
        let health = try IpcTestSupport.successBody(data: try IpcTestSupport.healthFixtureData(.aiReady))
        let oauthCode = corpus.first { $0.hasPrefix("lxai1e.") } ?? elevation

        var transcript: [String] = []

        func note(_ result: Result<some Any, IpcClientError>) {
            switch result {
            case .success(let value):
                // A verified answer prints as status and size only — never its headers.
                transcript.append(String(describing: value))
                transcript.append(String(reflecting: value))
            case .failure(let error):
                transcript.append(String(describing: error))
                transcript.append(error.userFacingReason)
                transcript.append(String(describing: error.coreClientError))
            }
        }

        // 1. A success.
        IpcStubURLProtocol.install { request, _ in IpcTestSupport.signedReply(for: request, status: 200, body: health) }
        note(await ipcResult { try await client.send(.health) })

        // 2. Every error envelope the table knows, signed and unsigned.
        for (status, code, signed) in [
            (401, "session_invalid", false), (401, "signature_invalid", true), (401, "timestamp_skew", true), (401, "nonce_replayed", true),
            (403, "origin_rejected", false), (403, "forbidden_scope", true), (403, "elevation_required", true), (403, "policy_denied", true),
            (403, "reauth_required", true), (404, "not_found", true), (409, "state_conflict", true), (413, "payload_too_large", true),
            (422, "validation_failed", true), (426, "version_unsupported", false), (429, "rate_limited", true), (503, "secrets_unavailable", false),
            (500, "internal", true), (418, "teapot", true),
        ] {
            let body = try IpcTestSupport.errorBody(code: code, message: "Fixed sentence for \(code).", hint: "A hint.", retryAfterSeconds: 3, supportedVersions: [1], details: ["open_url": "http://127.0.0.1:8787/approvals/x", "field": "problem"])
            IpcStubURLProtocol.install { request, _ in signed ? IpcTestSupport.signedReply(for: request, status: status, body: body) : IpcTestSupport.unsignedReply(status: status, body: body) }
            note(await ipcResult { try await client.send(.health) })
        }

        // 3. Transport failures, tampering, redirects.
        IpcStubURLProtocol.install { _, _ in .failure(.cannotConnectToHost) }
        note(await ipcResult { try await client.send(.health) })
        IpcStubURLProtocol.install { _, _ in IpcTestSupport.unsignedReply(status: 200, body: health) }
        note(await ipcResult { try await client.send(.health) })
        IpcStubURLProtocol.install { _, _ in .response(status: 302, headers: ["Location": "http://127.0.0.1:8787/x"], body: Data()) }
        note(await ipcResult { try await client.send(.health) })

        // 4. A request that carries secrets itself: an elevated operation and an OAuth code.
        IpcStubURLProtocol.install { request, _ in IpcTestSupport.signedReply(for: request, status: 403, body: try! IpcTestSupport.errorBody(code: "elevation_invalid")) }
        var elevated = IpcOperation(name: "remote-access/enable", method: "POST", pathAndQuery: "/ipc/v1/remote-access/enable", body: .object(["confirmed_origin": .string("https://my-mac.example-tailnet.ts.net"), "replace_existing": .bool(false)]), timeout: 35)
        elevated.elevation = elevation
        note(await ipcResult { try await client.send(elevated, nonce: "cmVtb3RlLWFjY2Vzcy1lbg") })

        IpcStubURLProtocol.install { request, _ in IpcTestSupport.signedReply(for: request, status: 422, body: try! IpcTestSupport.errorBody(code: "oauth_state_invalid", details: ["state": "unknown"])) }
        let oauth = IpcOperation(name: "oauth/callback", method: "POST", pathAndQuery: "/ipc/v1/oauth/callback", body: .object(["state": .string("st_01M4G0S7X2K9P4R6T8V1W3Y5Z7"), "code": .string(oauthCode)]), timeout: 20)
        note(await ipcResult { try await client.send(oauth) })

        // 5. A Supervisor that will not pair.
        let refusing = IpcTestSupport.client(session: IpcTestSupport.session(grant: nil, control: StubSupervisorControl(pair: [.failure(.refused("no live session"))])), sink: sink)
        note(await ipcResult { try await refusing.send(.health) })

        let everything = (transcript + [sink.text]).joined(separator: "\n")
        XCTAssertGreaterThanOrEqual(sink.events.count, 22, "every exchange logged one line")

        XCTAssertFalse(everything.contains(grant.token), "token in a log line or error")
        XCTAssertFalse(everything.contains(grant.signingKey), "signing key in a log line or error")
        XCTAssertFalse(everything.contains(elevation), "elevation in a log line or error")
        XCTAssertFalse(everything.contains("lxai1."), "token prefix")
        XCTAssertFalse(everything.contains("lxai1e."), "elevation prefix")
        XCTAssertFalse(everything.contains("Bearer "), "authorization header")
        XCTAssertFalse(everything.contains("v1="), "a signature")
        XCTAssertFalse(everything.contains(oauthCode), "the OAuth code")

        for secret in corpus {
            XCTAssertFalse(everything.contains(secret), "corpus value \(secret.prefix(12))… leaked")
        }

        for event in sink.events {
            XCTAssertFalse(event.requestId.isEmpty)
            XCTAssertTrue(["health", "remote-access/enable", "oauth/callback"].contains(event.operation))
        }
    }

    func testLogEventsAreBuiltFromNamesAndNumbersOnly() {
        let event = IpcLogEvent(operation: "health", status: 200, outcome: "signed", requestId: "01M4G06BJDY51YZX0PWRBQ8EBJ", durationMs: 3)

        XCTAssertEqual(event.description, "ipc health status=200 outcome=signed request_id=01M4G06BJDY51YZX0PWRBQ8EBJ duration_ms=3")
        let mirror = Mirror(reflecting: event)
        XCTAssertEqual(Set(mirror.children.compactMap(\.label)), ["operation", "status", "outcome", "requestId", "durationMs"])
    }

    func testTheOsLogSinkMarksTheRequestIdPrivate() throws {
        let source = try String(contentsOf: TestFiles.repositoryRoot.appending(path: "apps/macos/LaravelExecutiveAI/IPC/LoopbackIpcClient.swift"), encoding: .utf8)
        let loggerLines = source.split(separator: "\n").filter { $0.contains("logger.") }

        XCTAssertFalse(loggerLines.isEmpty)

        for line in loggerLines {
            XCTAssertTrue(line.contains("privacy: .private"), "every Logger line interpolates the request id as .private: \(line)")
            XCTAssertFalse(line.contains("token") || line.contains("signature") || line.contains("Authorization"), "a Logger line names a credential field: \(line)")
        }
    }
}
