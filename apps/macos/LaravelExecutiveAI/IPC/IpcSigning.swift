// Token derivation, request/response signing and elevation per docs/architecture/ipc-contract.md
// §3.3, §4.2, §4.5 and §7.3. Pure functions over `K`; `packaging/fixtures/ipc-signing.mjs` is the
// reference and `IPCSigningFixtureTests` proves byte-for-byte agreement on the fixtures.

import CryptoKit
import Foundation

enum IpcClient: String, Codable, Sendable, CaseIterable {
    case menubar
    case supervisor
}

/// `K`: the 256-bit HMAC key in `se.stafe.executive-ai.ipc.menubar`. Never all zero outside the
/// fixtures (ipc-contract §4.6 — the Supervisor refuses to write one).
struct IpcKey: Sendable, Equatable {
    static let byteCount = 32

    let bytes: Data

    init?(_ bytes: Data) {
        guard bytes.count == Self.byteCount, !Self.isAllZero(bytes) else {
            return nil
        }

        self.bytes = bytes
    }

    static func isAllZero(_ bytes: Data) -> Bool {
        bytes.allSatisfy { $0 == 0 }
    }

    #if DEBUG
    /// The documented fixture key (32 zero bytes). Only exists in debug builds, for tests.
    static let fixtureKey = IpcKey(unchecked: Data(repeating: 0, count: byteCount))

    private init(unchecked bytes: Data) {
        self.bytes = bytes
    }
    #endif
}

enum IpcSigning {
    static let lineFeed = "\n"

    // MARK: §3.3 token

    static func tokenMac(key: IpcKey, client: IpcClient, sessionId: String, expiresAt: Int) -> String {
        let message = ["lxai-ipc/v1/token", client.rawValue, sessionId, String(expiresAt)].joined(separator: lineFeed)
        return String(hmacHex(key: key.bytes, message: message).prefix(32))
    }

    static func token(key: IpcKey, client: IpcClient, sessionId: String, expiresAt: Int) -> String {
        "lxai1.\(client.rawValue).\(sessionId).\(expiresAt).\(tokenMac(key: key, client: client, sessionId: sessionId, expiresAt: expiresAt))"
    }

    static func signingKey(key: IpcKey, client: IpcClient, sessionId: String) -> Data {
        hmac(key: key.bytes, message: ["lxai-ipc/v1/signing-key", client.rawValue, sessionId].joined(separator: lineFeed))
    }

    // MARK: §4.2 request

    static func canonicalRequest(method: String, pathAndQuery: String, timestamp: Int, nonce: String, body: Data) -> String {
        ["LXAI-IPC-REQ/1", method, pathAndQuery, String(timestamp), nonce, sha256Hex(body)].joined(separator: lineFeed)
    }

    static func signRequest(signingKey: Data, method: String, pathAndQuery: String, timestamp: Int, nonce: String, body: Data) -> String {
        "v1=" + hmacHex(key: signingKey, message: canonicalRequest(method: method, pathAndQuery: pathAndQuery, timestamp: timestamp, nonce: nonce, body: body))
    }

    // MARK: §4.5 response

    static func canonicalResponse(status: Int, nonce: String, body: Data) -> String {
        ["LXAI-IPC-RES/1", String(status), nonce, sha256Hex(body)].joined(separator: lineFeed)
    }

    static func signResponse(signingKey: Data, status: Int, nonce: String, body: Data) -> String {
        "v1=" + hmacHex(key: signingKey, message: canonicalResponse(status: status, nonce: nonce, body: body))
    }

    static func responseSignatureIsValid(signingKey: Data, status: Int, nonce: String, body: Data, signature: String?) -> Bool {
        guard let signature else {
            return false
        }

        return constantTimeEquals(signResponse(signingKey: signingKey, status: status, nonce: nonce, body: body), signature)
    }

    // MARK: §7.3 elevation

    static func elevationMac(key: IpcKey, sessionId: String, operation: String, nonce: String, expiresAt: Int) -> String {
        let message = ["lxai-ipc/v1/elevation", sessionId, operation, nonce, String(expiresAt)].joined(separator: lineFeed)
        return String(hmacHex(key: key.bytes, message: message).prefix(32))
    }

    static func elevation(key: IpcKey, sessionId: String, operation: String, nonce: String, expiresAt: Int) -> String {
        "lxai1e.\(operation).\(nonce).\(expiresAt).\(elevationMac(key: key, sessionId: sessionId, operation: operation, nonce: nonce, expiresAt: expiresAt))"
    }

    // MARK: Primitives

    /// 16 random bytes, base64url without padding (22 characters) — ipc-contract §4.1.
    static func nonce(random: (Int) -> Data = SecureRandom.bytes) -> String {
        base64url(random(16))
    }

    static func base64url(_ data: Data) -> String {
        data.base64EncodedString()
            .replacingOccurrences(of: "+", with: "-")
            .replacingOccurrences(of: "/", with: "_")
            .replacingOccurrences(of: "=", with: "")
    }

    static func sha256Hex(_ data: Data) -> String {
        SHA256.hash(data: data).map { String(format: "%02x", $0) }.joined()
    }

    static func hmac(key: Data, message: String) -> Data {
        Data(HMAC<SHA256>.authenticationCode(for: Data(message.utf8), using: SymmetricKey(data: key)))
    }

    static func hmacHex(key: Data, message: String) -> String {
        hmac(key: key, message: message).map { String(format: "%02x", $0) }.joined()
    }

    static func constantTimeEquals(_ lhs: String, _ rhs: String) -> Bool {
        let left = Array(lhs.utf8)
        let right = Array(rhs.utf8)

        guard left.count == right.count else {
            return false
        }

        var difference: UInt8 = 0

        for (a, b) in zip(left, right) {
            difference |= a ^ b
        }

        return difference == 0
    }
}

enum SecureRandom {
    static func bytes(_ count: Int) -> Data {
        var data = Data(count: count)

        let status = data.withUnsafeMutableBytes { buffer -> Int32 in
            guard let base = buffer.baseAddress else {
                return errSecParam
            }

            return SecRandomCopyBytes(kSecRandomDefault, count, base)
        }

        precondition(status == errSecSuccess, "SecRandomCopyBytes failed")

        return data
    }
}

/// ULID (Crockford base32, 26 characters): 48-bit millisecond timestamp + 80 random bits.
enum ULID {
    private static let alphabet = Array("0123456789ABCDEFGHJKMNPQRSTVWXYZ")

    static func generate(now: Date = Date(), random: (Int) -> Data = SecureRandom.bytes) -> String {
        let milliseconds = UInt64(max(now.timeIntervalSince1970, 0) * 1000)
        var time = milliseconds
        var timeChars = [Character](repeating: "0", count: 10)

        for index in stride(from: 9, through: 0, by: -1) {
            timeChars[index] = alphabet[Int(time & 0x1F)]
            time >>= 5
        }

        let randomBytes = Array(random(10))
        var bits: UInt64 = 0
        var bitCount = 0
        var randomChars: [Character] = []

        for byte in randomBytes {
            bits = (bits << 8) | UInt64(byte)
            bitCount += 8

            while bitCount >= 5 {
                bitCount -= 5
                randomChars.append(alphabet[Int((bits >> UInt64(bitCount)) & 0x1F)])
            }
        }

        return String(timeChars + randomChars)
    }

    static func isValid(_ value: String) -> Bool {
        value.count == 26 && value.allSatisfy { alphabet.contains($0) } && value.first.map { "01234567".contains($0) } == true
    }
}

/// ipc-contract §3.2 step 3 — what `pair(client:)` returns. Keys are the wire names.
struct PairingGrant: Codable, Equatable, Sendable {
    let v: Int
    let client: IpcClient
    let sessionId: String
    let token: String
    /// base64url, 32 bytes, no padding.
    let signingKey: String
    let issuedAt: Date
    let expiresAt: Date
    let port: Int
    let ipcVersion: Int

    enum CodingKeys: String, CodingKey {
        case v
        case client
        case sessionId = "session_id"
        case token
        case signingKey = "signing_key"
        case issuedAt = "issued_at"
        case expiresAt = "expires_at"
        case port
        case ipcVersion = "ipc_version"
    }

    static let lifetime: TimeInterval = 30 * 24 * 3600

    static func mint(key: IpcKey, client: IpcClient, port: Int, now: Date, random: (Int) -> Data = SecureRandom.bytes) -> PairingGrant {
        let sessionId = ULID.generate(now: now, random: random)
        let expiresAt = now.addingTimeInterval(lifetime)
        let expiresAtSeconds = Int(expiresAt.timeIntervalSince1970)

        return PairingGrant(
            v: 1,
            client: client,
            sessionId: sessionId,
            token: IpcSigning.token(key: key, client: client, sessionId: sessionId, expiresAt: expiresAtSeconds),
            signingKey: IpcSigning.base64url(IpcSigning.signingKey(key: key, client: client, sessionId: sessionId)),
            issuedAt: now,
            expiresAt: expiresAt,
            port: port,
            ipcVersion: 1
        )
    }

    var signingKeyData: Data? {
        var base64 = signingKey.replacingOccurrences(of: "-", with: "+").replacingOccurrences(of: "_", with: "/")

        while base64.count % 4 != 0 {
            base64 += "="
        }

        return Data(base64Encoded: base64)
    }

    var expiresAtSeconds: Int {
        Int(expiresAt.timeIntervalSince1970)
    }

    static func encoder() -> JSONEncoder {
        let encoder = JSONEncoder()
        encoder.dateEncodingStrategy = .iso8601
        encoder.outputFormatting = [.sortedKeys]
        return encoder
    }

    static func decoder() -> JSONDecoder {
        let decoder = JSONDecoder()
        decoder.dateDecodingStrategy = .iso8601
        return decoder
    }
}

/// ipc-contract §7.3 — the one-shot elevation token the GUI sends as `X-Lxai-Elevation`.
struct Elevation: Codable, Equatable, Sendable {
    let value: String
    let operation: String
    let nonce: String
    let expiresAt: Date

    enum CodingKeys: String, CodingKey {
        case value
        case operation
        case nonce
        case expiresAt = "expires_at"
    }

    static let lifetime: TimeInterval = 60
}
