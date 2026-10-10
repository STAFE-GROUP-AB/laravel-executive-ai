// ADR-003 D3 broker: the frame codec and policy without a socket, the socket end to end with a
// recording peer verifier, and the real verifier's uid / pid-tree / requirement conditions on a
// socketpair (threat model T-36). The real Keychain is never involved.

import Foundation
import XCTest
@testable import LaravelExecutiveAI

final class SecretsBrokerTests: XCTestCase {
    private let peer = PeerIdentity(uid: getuid(), pid: 4242)

    private func seeded() throws -> (InMemoryKeychainStore, SecretsBrokerService, Audit) {
        let store = InMemoryKeychainStore()
        let secrets = SupervisorSecrets(store: store)
        try secrets.ensureAppKey()
        _ = try secrets.ensureIpcKey()

        let audit = Audit()
        let service = SecretsBrokerService(store: store) { audit.append($0) }

        return (store, service, audit)
    }

    final class Audit: @unchecked Sendable {
        private let lock = NSLock()
        private(set) var entries: [SecretsAuditEntry] = []

        func append(_ entry: SecretsAuditEntry) {
            lock.withLock { entries.append(entry) }
        }
    }

    private func request(_ object: [String: Any]) -> Data {
        SecretsFrame.encode(object)
    }

    private func decode(_ frame: Data) throws -> [String: Any] {
        let json = try XCTUnwrap(SecretsFrame.decode(frame))
        return try XCTUnwrap(JSONSerialization.jsonObject(with: json) as? [String: Any])
    }

    func testFrameCodec() {
        let frame = SecretsFrame.encode(["v": 1, "op": "health"])
        XCTAssertEqual([UInt8](frame.prefix(4)), [0, 0, 0, UInt8(frame.count - 4)])
        XCTAssertEqual(String(decoding: SecretsFrame.decode(frame)!, as: UTF8.self), "{\"op\":\"health\",\"v\":1}")
        XCTAssertNil(SecretsFrame.decode(Data([0, 0])))
        XCTAssertNil(SecretsFrame.decode(Data([0, 1, 0, 0, 1])), "length above the cap")
        XCTAssertNil(SecretsFrame.decode(Data([0, 0, 0, 5, 1])), "truncated")
    }

    func testGetReturnsTheEnvelopeAndAuditsWithoutTheValue() throws {
        let (store, service, audit) = try seeded()

        let reply = try decode(service.handle(frame: request(["v": 1, "op": "get", "service": SecretService.appKey, "account": "default"]), peer: peer))

        XCTAssertEqual(reply["ok"] as? Bool, true)
        let value = try XCTUnwrap(reply["value"] as? String)
        XCTAssertEqual(Data(value.utf8), store.snapshot[SupervisorSecrets.appKeyRef]?.data)
        XCTAssertTrue(value.contains("base64:"))

        let entry = try XCTUnwrap(audit.entries.last)
        XCTAssertEqual(entry.operation, "get")
        XCTAssertEqual(entry.service, SecretService.appKey)
        XCTAssertEqual(entry.account, "default")
        XCTAssertEqual(entry.pid, 4242)
        XCTAssertEqual(entry.result, "ok")
        XCTAssertFalse("\(entry)".contains("base64:"), "the audit entry never carries the value")
    }

    func testPolicyRefusesWhatItMust() throws {
        let (_, service, _) = try seeded()

        XCTAssertEqual(try decode(service.handle(frame: request(["v": 1, "op": "get", "service": "se.other.service", "account": "default"]), peer: peer))["error"] as? String, "forbidden")
        XCTAssertEqual(try decode(service.handle(frame: request(["v": 1, "op": "get", "service": SecretService.appKey, "account": "../x"]), peer: peer))["error"] as? String, "bad_request")
        XCTAssertEqual(try decode(service.handle(frame: request(["v": 1, "op": "get", "service": SecretService.email, "account": "01M4G0QK2T6Z5P9Y3R7W1A8C4E"]), peer: peer))["error"] as? String, "not_found")
        XCTAssertEqual(try decode(service.handle(frame: request(["v": 2, "op": "get"]), peer: peer))["error"] as? String, "bad_request")
        XCTAssertEqual(try decode(service.handle(frame: request(["v": 1, "op": "explode"]), peer: peer))["error"] as? String, "bad_request")
        XCTAssertEqual(try decode(service.handle(frame: Data([0, 0, 0, 1, 0x7B]), peer: peer))["error"] as? String, "bad_request")

        service.lock()
        let locked = try decode(service.handle(frame: request(["v": 1, "op": "get", "service": SecretService.appKey, "account": "default"]), peer: peer))
        XCTAssertEqual(locked["error"] as? String, "locked")
        XCTAssertEqual(try decode(service.handle(frame: request(["v": 1, "op": "health"]), peer: peer))["locked"] as? Bool, true)
        service.unlock()
        XCTAssertEqual(try decode(service.handle(frame: request(["v": 1, "op": "get", "service": SecretService.appKey, "account": "default"]), peer: peer))["ok"] as? Bool, true)
    }

    func testPutListDeleteRoundTrip() throws {
        let (store, service, _) = try seeded()
        let account = "01M4G0T1P8X3M2K7Q9R5V4W6YB"

        XCTAssertEqual(try decode(service.handle(frame: request(["v": 1, "op": "put", "service": SecretService.email, "account": account, "value": "{\"v\":1,\"kind\":\"oauth2\"}"]), peer: peer))["ok"] as? Bool, true)
        XCTAssertEqual(store.snapshot[KeychainItemRef(service: SecretService.email, account: account)]?.label, "Laravel Executive AI — email")

        let listed = try decode(service.handle(frame: request(["v": 1, "op": "list", "service": SecretService.email]), peer: peer))
        XCTAssertEqual(listed["accounts"] as? [String], [account])

        XCTAssertEqual(try decode(service.handle(frame: request(["v": 1, "op": "put", "service": SecretService.email, "account": account, "value": ""]), peer: peer))["error"] as? String, "bad_request")
        XCTAssertEqual(try decode(service.handle(frame: request(["v": 1, "op": "put", "service": SecretService.email, "account": account, "value": String(repeating: "x", count: 40_000)]), peer: peer))["error"] as? String, "bad_request")

        XCTAssertEqual(try decode(service.handle(frame: request(["v": 1, "op": "delete", "service": SecretService.email, "account": account]), peer: peer))["ok"] as? Bool, true)
        XCTAssertEqual(try decode(service.handle(frame: request(["v": 1, "op": "list", "service": SecretService.email]), peer: peer))["accounts"] as? [String], [])
    }

    func testSocketServesAnAcceptedPeerAndIgnoresARejectedOne() throws {
        let (store, service, _) = try seeded()
        let path = ServicesTestSupport.shortSocketPath("ok")
        let verifier = RecordingPeerVerifier(verdict: .accepted(peer))
        let server = SecretsBrokerServer(socketPath: path, service: service, verifier: verifier, spawnedPids: { [77] })
        try server.start()
        defer { server.stop() }

        let reply = try decode(try Self.exchange(path: path, frame: request(["v": 1, "op": "get", "service": SecretService.ipcMenubar, "account": "default"])))
        XCTAssertEqual(reply["ok"] as? Bool, true)
        XCTAssertEqual(Data((reply["value"] as! String).utf8), store.snapshot[SupervisorSecrets.ipcKeyRef]?.data)
        XCTAssertEqual(verifier.seenSpawned, [[77]], "the live pid set is consulted per connection")

        let attributes = try FileManager.default.attributesOfItem(atPath: path.path)
        XCTAssertEqual((attributes[.posixPermissions] as? NSNumber)?.intValue, 0o600)

        server.stop()
        XCTAssertFalse(FileManager.default.fileExists(atPath: path.path), "stop unlinks the socket")

        let rejectedPath = ServicesTestSupport.shortSocketPath("no")
        let rejectedServer = SecretsBrokerServer(socketPath: rejectedPath, service: service, verifier: RecordingPeerVerifier(verdict: .rejected("test")), spawnedPids: { [] })
        try rejectedServer.start()
        defer { rejectedServer.stop() }

        let silence = try Self.exchange(path: rejectedPath, frame: request(["v": 1, "op": "health"]))
        XCTAssertEqual(silence, Data(), "a rejected peer gets the connection closed, not a byte")
    }

    func testPeerTreeWalk() {
        let parents: [pid_t: pid_t] = [500: 400, 400: 300, 300: 1, 900: 900]

        XCTAssertTrue(PeerTree.isSupervised(pid: 400, spawned: [400], parent: { parents[$0] }))
        XCTAssertTrue(PeerTree.isSupervised(pid: 500, spawned: [300], parent: { parents[$0] }), "a grandchild of a spawned child")
        XCTAssertFalse(PeerTree.isSupervised(pid: 500, spawned: [999], parent: { parents[$0] }))
        XCTAssertFalse(PeerTree.isSupervised(pid: 300, spawned: [1], parent: { parents[$0] }), "launchd is never a supervised ancestor")
        XCTAssertFalse(PeerTree.isSupervised(pid: 900, spawned: [1], parent: { parents[$0] }), "a self-parent loop terminates")
        XCTAssertEqual(PeerTree.parentPid(of: getpid()), getppid())
    }

    func testStrictVerifierConditionsOnASocketPair() throws {
        var fds: [Int32] = [0, 0]
        XCTAssertEqual(socketpair(AF_UNIX, SOCK_STREAM, 0, &fds), 0)
        defer { close(fds[0]); close(fds[1]) }

        let unsigned = StrictPeerVerifier(requirement: nil, allowUnsignedPeers: false)
        XCTAssertEqual(unsigned.verify(socket: fds[0], spawned: []), .rejected("peer is not a supervised process"))
        XCTAssertEqual(unsigned.verify(socket: fds[0], spawned: [getpid()]), .rejected("no signing requirement is available"))

        // The peer of a socketpair is this process. (The descendant case is `testPeerTreeWalk`:
        // under xcodebuild the test host's parent is launchd, which is never a supervised pid.)
        let lab = StrictPeerVerifier(requirement: nil, allowUnsignedPeers: true)
        XCTAssertEqual(lab.verify(socket: fds[0], spawned: [getpid()]), .accepted(PeerIdentity(uid: getuid(), pid: getpid())))

        let otherUser = StrictPeerVerifier(requirement: nil, allowUnsignedPeers: true, ownUid: getuid() &+ 1)
        XCTAssertEqual(otherUser.verify(socket: fds[0], spawned: [getpid()]), .rejected("peer runs as another user"))

        // An unsigned test host never satisfies a Team ID requirement: the code check fails closed.
        let signed = StrictPeerVerifier(requirement: "anchor apple generic and certificate leaf[subject.OU] = \"ZZZZZZZZZZ\"", allowUnsignedPeers: true)
        if case .rejected(let reason) = signed.verify(socket: fds[0], spawned: [getpid()]) {
            XCTAssertTrue(reason.contains("signing requirement") || reason.contains("audit token"), reason)
        } else {
            XCTFail("an unsigned peer must not satisfy a Team ID requirement")
        }
    }

    /// Connects, writes one frame, reads everything until the server closes.
    static func exchange(path: URL, frame: Data) throws -> Data {
        let fd = socket(AF_UNIX, SOCK_STREAM, 0)
        XCTAssertGreaterThanOrEqual(fd, 0)
        defer { close(fd) }

        var address = sockaddr_un()
        address.sun_family = sa_family_t(AF_UNIX)
        let bytes = Array(path.path.utf8)
        withUnsafeMutableBytes(of: &address.sun_path) { buffer in
            buffer.copyBytes(from: bytes)
            buffer[bytes.count] = 0
        }

        let connected = withUnsafePointer(to: &address) { pointer in
            pointer.withMemoryRebound(to: sockaddr.self, capacity: 1) { connect(fd, $0, socklen_t(MemoryLayout<sockaddr_un>.size)) }
        }
        XCTAssertEqual(connected, 0, "connect failed: \(errno)")

        var timeout = timeval(tv_sec: 5, tv_usec: 0)
        setsockopt(fd, SOL_SOCKET, SO_RCVTIMEO, &timeout, socklen_t(MemoryLayout<timeval>.size))
        UnixSocketServer.writeAll(fd, frame)

        var received = Data()
        var buffer = [UInt8](repeating: 0, count: 4096)

        while true {
            let count = read(fd, &buffer, buffer.count)

            guard count > 0 else {
                break
            }

            received.append(contentsOf: buffer[0..<count])
        }

        return received
    }
}
