// Byte-for-byte agreement with packaging/fixtures/ipc-signing.mjs on every fixture in
// packaging/fixtures/ipc/ (ipc-contract §4.6): token MAC, request signature, response signature
// and the elevation header, all under the test key (32 zero bytes).

import Foundation
import XCTest
@testable import LaravelExecutiveAI

final class IPCSigningFixtureTests: XCTestCase {
    private struct Fixture {
        let name: String
        let request: [String: Any]
        let response: [String: Any]
    }

    private func fixtures() throws -> [Fixture] {
        let directory = TestFiles.repositoryRoot.appending(path: "packaging/fixtures/ipc")
        let names = try FileManager.default.contentsOfDirectory(atPath: directory.path).filter { $0.hasSuffix(".json") }.sorted()
        XCTAssertFalse(names.isEmpty)

        return try names.map { name in
            let data = try Data(contentsOf: directory.appending(path: name))
            let object = try XCTUnwrap(try JSONSerialization.jsonObject(with: data) as? [String: Any])

            return Fixture(
                name: name,
                request: try XCTUnwrap(object["request"] as? [String: Any]),
                response: try XCTUnwrap(object["response"] as? [String: Any])
            )
        }
    }

    private func bodyBytes(_ body: Any?) throws -> Data {
        guard let body, !(body is NSNull) else {
            return Data()
        }

        let raw = try JSONSerialization.data(withJSONObject: body, options: [.fragmentsAllowed])
        return Data(try JCS.canonicalize(raw).utf8)
    }

    func testEveryFixtureTokenRequestAndResponseSignatureReproduce() throws {
        let key = IpcKey.fixtureKey
        var verified = 0

        for fixture in try fixtures() {
            let headers = try XCTUnwrap(fixture.request["headers"] as? [String: String], fixture.name)
            let authorization = try XCTUnwrap(headers["Authorization"], fixture.name)
            let token = String(authorization.dropFirst("Bearer ".count))
            let parts = token.split(separator: ".").map(String.init)
            XCTAssertEqual(parts.count, 5, fixture.name)
            XCTAssertEqual(parts[0], "lxai1", fixture.name)

            let client = try XCTUnwrap(IpcClient(rawValue: parts[1]), fixture.name)
            let sessionId = parts[2]
            let expiresAt = try XCTUnwrap(Int(parts[3]), fixture.name)

            XCTAssertEqual(IpcSigning.token(key: key, client: client, sessionId: sessionId, expiresAt: expiresAt), token, "\(fixture.name): token")

            let signingKey = IpcSigning.signingKey(key: key, client: client, sessionId: sessionId)
            let method = try XCTUnwrap(fixture.request["method"] as? String)
            let path = try XCTUnwrap(fixture.request["path"] as? String)
            let timestamp = try XCTUnwrap(Int(headers["X-Lxai-Timestamp"] ?? ""))
            let nonce = try XCTUnwrap(headers["X-Lxai-Nonce"])
            let requestBody = try bodyBytes(fixture.request["body"])
            let computed = IpcSigning.signRequest(signingKey: signingKey, method: method, pathAndQuery: path, timestamp: timestamp, nonce: nonce, body: requestBody)
            let recordedErrorCode = ((fixture.response["body"] as? [String: Any])?["error"] as? [String: Any])?["code"] as? String

            if recordedErrorCode == "signature_invalid" {
                // The one fixture whose request signature is wrong on purpose.
                XCTAssertNotEqual(computed, headers["X-Lxai-Signature"], "\(fixture.name): the recorded signature must not verify")
            } else {
                XCTAssertEqual(computed, headers["X-Lxai-Signature"], "\(fixture.name): request signature")
            }

            if let elevation = headers["X-Lxai-Elevation"] {
                let elevationParts = elevation.split(separator: ".").map(String.init)
                XCTAssertEqual(elevationParts.count, 5)
                XCTAssertEqual(
                    IpcSigning.elevation(key: key, sessionId: sessionId, operation: elevationParts[1], nonce: elevationParts[2], expiresAt: try XCTUnwrap(Int(elevationParts[3]))),
                    elevation,
                    "\(fixture.name): elevation"
                )
            }

            let responseHeaders = try XCTUnwrap(fixture.response["headers"] as? [String: String])
            let status = try XCTUnwrap(fixture.response["status"] as? Int)

            if let responseSignature = responseHeaders["X-Lxai-Signature"] {
                let responseBody = try bodyBytes(fixture.response["body"])
                XCTAssertTrue(
                    IpcSigning.responseSignatureIsValid(signingKey: signingKey, status: status, nonce: nonce, body: responseBody, signature: responseSignature),
                    "\(fixture.name): response signature"
                )
                XCTAssertFalse(IpcSigning.responseSignatureIsValid(signingKey: signingKey, status: status == 200 ? 503 : 200, nonce: nonce, body: responseBody, signature: responseSignature), "\(fixture.name): tampered status")
            }

            verified += 1
        }

        XCTAssertGreaterThanOrEqual(verified, 16)
    }

    func testHealthFixtureValuesAreTheDocumentedOnes() {
        let key = IpcKey.fixtureKey

        XCTAssertEqual(IpcSigning.tokenMac(key: key, client: .menubar, sessionId: "01M4G0QK2T6Z5P9Y3R7W1A8C4E", expiresAt: 1_794_240_000), "f8c50b991bd35d59ed0fa74ffdfe4bbf")
        XCTAssertEqual(IpcSigning.canonicalRequest(method: "GET", pathAndQuery: "/ipc/v1/health", timestamp: 1_791_648_005, nonce: "aGVhbHRoLi4uLi4uLi4uLg", body: Data()),
                       "LXAI-IPC-REQ/1\nGET\n/ipc/v1/health\n1791648005\naGVhbHRoLi4uLi4uLi4uLg\ne3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855")
    }

    func testPairingGrantMintsAVerifiableSessionAndRoundTrips() throws {
        let key = IpcKey(Data(repeating: 9, count: 32))!
        let now = Date(timeIntervalSince1970: 1_791_648_000)
        let grant = PairingGrant.mint(key: key, client: .menubar, port: 8787, now: now) { count in Data(repeating: 0xAB, count: count) }

        XCTAssertTrue(ULID.isValid(grant.sessionId))
        XCTAssertEqual(grant.expiresAt.timeIntervalSince(now), 30 * 24 * 3600)
        XCTAssertEqual(grant.token, IpcSigning.token(key: key, client: .menubar, sessionId: grant.sessionId, expiresAt: grant.expiresAtSeconds))
        XCTAssertEqual(grant.signingKeyData, IpcSigning.signingKey(key: key, client: .menubar, sessionId: grant.sessionId))
        XCTAssertEqual(grant.signingKey.count, 43, "base64url of 32 bytes, no padding")
        XCTAssertFalse(grant.signingKey.contains("="))

        let encoded = try PairingGrant.encoder().encode(grant)
        let json = try XCTUnwrap(JSONSerialization.jsonObject(with: encoded) as? [String: Any])
        XCTAssertEqual(Set(json.keys), ["v", "client", "session_id", "token", "signing_key", "issued_at", "expires_at", "port", "ipc_version"])
        XCTAssertEqual(try PairingGrant.decoder().decode(PairingGrant.self, from: encoded), grant)

        let supervisor = SupervisorGrant.derive(key: key, now: now)
        XCTAssertTrue(supervisor.token.hasPrefix("lxai1.supervisor."))
    }

    func testNonceAndUlidShapes() {
        let nonce = IpcSigning.nonce()
        XCTAssertEqual(nonce.count, 22)
        XCTAssertFalse(nonce.contains("=") || nonce.contains("+") || nonce.contains("/"))

        let ulid = ULID.generate(now: Date(timeIntervalSince1970: 1_791_648_000))
        XCTAssertTrue(ULID.isValid(ulid))
        XCTAssertEqual(ulid.count, 26)
        XCTAssertNotEqual(ULID.generate(), ULID.generate())
        XCTAssertFalse(ULID.isValid("01M4G0QK2T6Z5P9Y3R7W1A8C4"))
    }

    func testConstantTimeCompare() {
        XCTAssertTrue(IpcSigning.constantTimeEquals("abc", "abc"))
        XCTAssertFalse(IpcSigning.constantTimeEquals("abc", "abd"))
        XCTAssertFalse(IpcSigning.constantTimeEquals("abc", "abcd"))
        XCTAssertFalse(IpcSigning.responseSignatureIsValid(signingKey: Data(repeating: 1, count: 32), status: 200, nonce: "n", body: Data(), signature: nil))
    }
}
