// The secrets broker (ADR-003 D3): the only way a PHP child obtains a secret.
//
// Protocol (D3.1): one length-prefixed JSON frame per connection — 4-byte big-endian length,
// then UTF-8 JSON — request `{"v":1,"op":"get|put|delete|list|health","service":…,"account":…,
// "value":…}`, response `{"v":1,"ok":true,…}` or `{"v":1,"ok":false,"error":"<code>"}`. The
// broker never caches: every `get` is a Keychain read. Every operation is audited by service,
// account, op, pid and result — never the value (D7).
//
// Who is answered (D3.2) is `PeerVerifying`'s decision, taken before a single byte of the
// request is read. The policy and the codec are synchronous and pure enough to be tested
// without a socket; `SecretsBrokerServer` ties them to `UnixSocketServer`.

import Foundation
import Security
import Synchronization

// MARK: - Frames

enum SecretsFrame {
    static let maxLength = 64 * 1024

    static func encode(_ object: [String: Any]) -> Data {
        guard JSONSerialization.isValidJSONObject(object),
              let json = try? JSONSerialization.data(withJSONObject: object, options: [.sortedKeys])
        else {
            return encode(["v": 1, "ok": false, "error": "internal"])
        }

        var frame = Data(count: 4)
        let length = UInt32(json.count)
        frame[0] = UInt8((length >> 24) & 0xFF)
        frame[1] = UInt8((length >> 16) & 0xFF)
        frame[2] = UInt8((length >> 8) & 0xFF)
        frame[3] = UInt8(length & 0xFF)
        frame.append(json)

        return frame
    }

    /// Splits a complete frame; nil when the prefix is malformed or the length exceeds the cap.
    static func decode(_ data: Data) -> Data? {
        guard data.count >= 4 else {
            return nil
        }

        let bytes = [UInt8](data.prefix(4))
        let length = Int(bytes[0]) << 24 | Int(bytes[1]) << 16 | Int(bytes[2]) << 8 | Int(bytes[3])

        guard length > 0, length <= maxLength, data.count >= 4 + length else {
            return nil
        }

        return data.subdata(in: 4..<(4 + length))
    }
}

// MARK: - Requests and policy

struct SecretsRequest: Equatable, Sendable {
    enum Operation: String, Sendable {
        case get, put, delete, list, health
    }

    let operation: Operation
    let service: String?
    let account: String?
    let value: String?

    static func parse(_ json: Data) -> SecretsRequest? {
        guard let object = try? JSONSerialization.jsonObject(with: json) as? [String: Any],
              object["v"] as? Int == 1,
              let rawOperation = object["op"] as? String,
              let operation = Operation(rawValue: rawOperation)
        else {
            return nil
        }

        return SecretsRequest(
            operation: operation,
            service: object["service"] as? String,
            account: object["account"] as? String,
            value: object["value"] as? String
        )
    }
}

struct SecretsAuditEntry: Equatable, Sendable {
    let operation: String
    let service: String?
    let account: String?
    let pid: pid_t
    let result: String
}

/// The policy half of the broker: which services exist, what a well-formed account looks like,
/// the `locked` state, and the Keychain round trip. No sockets here.
final class SecretsBrokerService: Sendable {
    static let maxValueBytes = 32 * 1024
    static let maxAccountLength = 200

    private let store: any KeychainStore
    private let audit: @Sendable (SecretsAuditEntry) -> Void
    private let lockedState = Mutex(false)

    init(store: any KeychainStore, audit: @escaping @Sendable (SecretsAuditEntry) -> Void = { _ in }) {
        self.store = store
        self.audit = audit
    }

    /// ADR-003 D3.2 (4) / D8: the compromise response. Every `get` is refused until cleared.
    var isLocked: Bool {
        lockedState.withLock { $0 }
    }

    func lock() {
        lockedState.withLock { $0 = true }
    }

    func unlock() {
        lockedState.withLock { $0 = false }
    }

    /// One frame in, one frame out.
    func handle(frame: Data, peer: PeerIdentity) -> Data {
        guard let json = SecretsFrame.decode(frame), let request = SecretsRequest.parse(json) else {
            audit(SecretsAuditEntry(operation: "?", service: nil, account: nil, pid: peer.pid, result: "bad_request"))
            return SecretsFrame.encode(Self.error("bad_request"))
        }

        return SecretsFrame.encode(handle(request, peer: peer))
    }

    func handle(_ request: SecretsRequest, peer: PeerIdentity) -> [String: Any] {
        let result = respond(request)
        let outcome = result["ok"] as? Bool == true ? "ok" : (result["error"] as? String ?? "error")
        audit(SecretsAuditEntry(operation: request.operation.rawValue, service: request.service, account: request.account, pid: peer.pid, result: outcome))

        return result
    }

    private func respond(_ request: SecretsRequest) -> [String: Any] {
        if request.operation == .health {
            return ["v": 1, "ok": true, "locked": isLocked]
        }

        guard let service = request.service, SecretService.inventory.contains(service) else {
            return Self.error("forbidden")
        }

        if request.operation == .list {
            guard let accounts = try? store.accounts(service: service) else {
                return Self.error("unavailable")
            }

            return ["v": 1, "ok": true, "accounts": accounts]
        }

        guard let account = request.account, Self.isValidAccount(account) else {
            return Self.error("bad_request")
        }

        let ref = KeychainItemRef(service: service, account: account)

        switch request.operation {
        case .get:
            guard !isLocked else {
                return Self.error("locked")
            }

            do {
                guard let data = try store.read(ref), let value = String(data: data, encoding: .utf8) else {
                    return Self.error("not_found")
                }

                return ["v": 1, "ok": true, "value": value]
            } catch {
                return Self.error("unavailable")
            }

        case .put:
            guard let value = request.value, !value.isEmpty, value.utf8.count <= Self.maxValueBytes else {
                return Self.error("bad_request")
            }

            do {
                try store.write(ref, data: Data(value.utf8), label: SecretService.label(service))
                return ["v": 1, "ok": true]
            } catch {
                return Self.error("unavailable")
            }

        case .delete:
            do {
                try store.delete(ref)
                return ["v": 1, "ok": true]
            } catch {
                return Self.error("unavailable")
            }

        case .list, .health:
            return Self.error("bad_request")
        }
    }

    static func isValidAccount(_ account: String) -> Bool {
        guard !account.isEmpty, account.count <= maxAccountLength else {
            return false
        }

        return account.allSatisfy { character in
            character.isLetter || character.isNumber || character == "-" || character == "_" || character == "."
        } && account.allSatisfy(\.isASCII)
    }

    static func error(_ code: String) -> [String: Any] {
        ["v": 1, "ok": false, "error": code]
    }
}

// MARK: - Peer verification (ADR-003 D3.2, threat model T-36)

struct PeerIdentity: Equatable, Sendable {
    let uid: uid_t
    let pid: pid_t
}

enum PeerVerdict: Equatable, Sendable {
    case accepted(PeerIdentity)
    case rejected(String)
}

protocol PeerVerifying: Sendable {
    /// Decides before the request is read. `spawned` is the live pids of the supervised children.
    func verify(socket: Int32, spawned: Set<pid_t>) -> PeerVerdict
}

/// Pure half of the check: is `pid` one of `spawned` or a descendant, walking the parent chain.
enum PeerTree {
    static let maxDepth = 16

    static func isSupervised(pid: pid_t, spawned: Set<pid_t>, parent: (pid_t) -> pid_t?) -> Bool {
        var current = pid
        var depth = 0

        while depth < maxDepth {
            if spawned.contains(current) {
                return true
            }

            guard let next = parent(current), next > 1, next != current else {
                return false
            }

            current = next
            depth += 1
        }

        return false
    }

    /// `kinfo_proc.kp_eproc.e_ppid` through `sysctl KERN_PROC_PID`.
    static func parentPid(of pid: pid_t) -> pid_t? {
        var mib: [Int32] = [CTL_KERN, KERN_PROC, KERN_PROC_PID, pid]
        var info = kinfo_proc()
        var size = MemoryLayout<kinfo_proc>.stride

        guard sysctl(&mib, UInt32(mib.count), &info, &size, nil, 0) == 0, size > 0 else {
            return nil
        }

        return info.kp_eproc.e_ppid
    }
}

/// The four conjunctive conditions of ADR-003 D3.2. `requirement` is the FrankenPHP designated
/// requirement for our Team ID; without one (an unsigned build) every peer is rejected unless
/// `allowUnsignedPeers`, which only a DEBUG build can set.
struct StrictPeerVerifier: PeerVerifying {
    let requirement: String?
    let allowUnsignedPeers: Bool
    let ownUid: uid_t

    init(requirement: String?, allowUnsignedPeers: Bool = false, ownUid: uid_t = getuid()) {
        self.requirement = requirement
        self.allowUnsignedPeers = allowUnsignedPeers
        self.ownUid = ownUid
    }

    func verify(socket: Int32, spawned: Set<pid_t>) -> PeerVerdict {
        var uid: uid_t = 0
        var gid: gid_t = 0

        guard getpeereid(socket, &uid, &gid) == 0 else {
            return .rejected("peer credentials unavailable")
        }

        guard uid == ownUid else {
            return .rejected("peer runs as another user")
        }

        guard let pid = Self.peerPid(socket) else {
            return .rejected("peer pid unavailable")
        }

        guard PeerTree.isSupervised(pid: pid, spawned: spawned, parent: PeerTree.parentPid(of:)) else {
            return .rejected("peer is not a supervised process")
        }

        let identity = PeerIdentity(uid: uid, pid: pid)

        guard let requirement else {
            return allowUnsignedPeers ? .accepted(identity) : .rejected("no signing requirement is available")
        }

        guard let token = Self.peerAuditToken(socket) else {
            return .rejected("peer audit token unavailable")
        }

        guard Self.codeSatisfies(requirement: requirement, auditToken: token) else {
            return .rejected("peer code does not satisfy the signing requirement")
        }

        return .accepted(identity)
    }

    static func peerPid(_ socket: Int32) -> pid_t? {
        var pid: pid_t = 0
        var size = socklen_t(MemoryLayout<pid_t>.size)

        guard getsockopt(socket, SOL_LOCAL, LOCAL_PEERPID, &pid, &size) == 0, pid > 0 else {
            return nil
        }

        return pid
    }

    static func peerAuditToken(_ socket: Int32) -> Data? {
        var token = audit_token_t()
        var size = socklen_t(MemoryLayout<audit_token_t>.size)

        guard getsockopt(socket, SOL_LOCAL, LOCAL_PEERTOKEN, &token, &size) == 0, size == socklen_t(MemoryLayout<audit_token_t>.size) else {
            return nil
        }

        return withUnsafeBytes(of: &token) { Data($0) }
    }

    static func codeSatisfies(requirement: String, auditToken: Data) -> Bool {
        var code: SecCode?
        let attributes: [CFString: Any] = [kSecGuestAttributeAudit: auditToken]

        guard SecCodeCopyGuestWithAttributes(nil, attributes as CFDictionary, [], &code) == errSecSuccess, let code else {
            return false
        }

        var secRequirement: SecRequirement?

        guard SecRequirementCreateWithString(requirement as CFString, [], &secRequirement) == errSecSuccess, let secRequirement else {
            return false
        }

        return SecCodeCheckValidity(code, [], secRequirement) == errSecSuccess
    }
}

/// Tests: answers every connection with a fixed verdict and records what it saw.
final class RecordingPeerVerifier: PeerVerifying, @unchecked Sendable {
    private let lock = NSLock()
    private let verdict: PeerVerdict
    private(set) var seenSpawned: [Set<pid_t>] = []

    init(verdict: PeerVerdict) {
        self.verdict = verdict
    }

    func verify(socket: Int32, spawned: Set<pid_t>) -> PeerVerdict {
        lock.withLock { seenSpawned.append(spawned) }
        return verdict
    }
}

// MARK: - Unix socket server

enum UnixSocketError: Error, Equatable, Sendable {
    case pathTooLong
    case socketFailed(Int32)
    case bindFailed(Int32)
    case listenFailed(Int32)
    case directoryFailed
}

/// `run/secrets.sock`: directory 0700, socket 0600, one frame per connection, handled on a
/// background queue. The handler receives the connected descriptor and must close it.
final class UnixSocketServer: @unchecked Sendable {
    let path: URL
    private let handler: @Sendable (Int32) -> Void
    private let lock = NSLock()
    private var listening: Int32 = -1
    private var thread: Thread?
    private let queue = DispatchQueue(label: "se.stafe.executive-ai.secrets-broker", attributes: .concurrent)

    init(path: URL, handler: @escaping @Sendable (Int32) -> Void) {
        self.path = path
        self.handler = handler
    }

    func start() throws {
        let fileManager = FileManager.default
        let directory = path.deletingLastPathComponent()

        // `run/` is created 0700 (ADR-003 D3); an existing parent (the tests' temporary
        // directory) is left as it is.
        if !fileManager.fileExists(atPath: directory.path) {
            do {
                try fileManager.createDirectory(at: directory, withIntermediateDirectories: true, attributes: [.posixPermissions: 0o700])
            } catch {
                throw UnixSocketError.directoryFailed
            }
        }

        var address = sockaddr_un()
        let pathBytes = Array(path.path.utf8)
        let capacity = MemoryLayout.size(ofValue: address.sun_path) - 1

        guard pathBytes.count <= capacity else {
            throw UnixSocketError.pathTooLong
        }

        address.sun_family = sa_family_t(AF_UNIX)
        withUnsafeMutableBytes(of: &address.sun_path) { buffer in
            buffer.copyBytes(from: pathBytes)
            buffer[pathBytes.count] = 0
        }

        unlink(path.path)

        let fd = socket(AF_UNIX, SOCK_STREAM, 0)

        guard fd >= 0 else {
            throw UnixSocketError.socketFailed(errno)
        }

        let bound = withUnsafePointer(to: &address) { pointer in
            pointer.withMemoryRebound(to: sockaddr.self, capacity: 1) { bind(fd, $0, socklen_t(MemoryLayout<sockaddr_un>.size)) }
        }

        guard bound == 0 else {
            let code = errno
            close(fd)
            throw UnixSocketError.bindFailed(code)
        }

        chmod(path.path, 0o600)

        guard listen(fd, 16) == 0 else {
            let code = errno
            close(fd)
            unlink(path.path)
            throw UnixSocketError.listenFailed(code)
        }

        lock.withLock { listening = fd }

        let thread = Thread { [weak self] in
            self?.acceptLoop(fd)
        }
        thread.name = "secrets-broker-accept"
        thread.start()
        lock.withLock { self.thread = thread }
    }

    func stop() {
        let fd = lock.withLock { () -> Int32 in
            let current = listening
            listening = -1
            return current
        }

        if fd >= 0 {
            shutdown(fd, SHUT_RDWR)
            close(fd)
        }

        unlink(path.path)
    }

    private func acceptLoop(_ fd: Int32) {
        while true {
            let client = accept(fd, nil, nil)

            guard client >= 0 else {
                if errno == EINTR {
                    continue
                }

                return
            }

            var timeout = timeval(tv_sec: 5, tv_usec: 0)
            setsockopt(client, SOL_SOCKET, SO_RCVTIMEO, &timeout, socklen_t(MemoryLayout<timeval>.size))
            setsockopt(client, SOL_SOCKET, SO_SNDTIMEO, &timeout, socklen_t(MemoryLayout<timeval>.size))

            let handler = self.handler
            queue.async {
                handler(client)
            }
        }
    }

    // MARK: Frame I/O on a descriptor

    static func readFrame(_ fd: Int32) -> Data? {
        guard let prefix = readExactly(fd, count: 4) else {
            return nil
        }

        let bytes = [UInt8](prefix)
        let length = Int(bytes[0]) << 24 | Int(bytes[1]) << 16 | Int(bytes[2]) << 8 | Int(bytes[3])

        guard length > 0, length <= SecretsFrame.maxLength, let body = readExactly(fd, count: length) else {
            return nil
        }

        return prefix + body
    }

    static func writeAll(_ fd: Int32, _ data: Data) {
        var offset = 0

        while offset < data.count {
            let written = data.withUnsafeBytes { buffer -> Int in
                guard let base = buffer.baseAddress else {
                    return -1
                }

                return write(fd, base.advanced(by: offset), data.count - offset)
            }

            guard written > 0 else {
                return
            }

            offset += written
        }
    }

    private static func readExactly(_ fd: Int32, count: Int) -> Data? {
        var data = Data(count: count)
        var offset = 0

        while offset < count {
            let received = data.withUnsafeMutableBytes { buffer -> Int in
                guard let base = buffer.baseAddress else {
                    return -1
                }

                return read(fd, base.advanced(by: offset), count - offset)
            }

            guard received > 0 else {
                return nil
            }

            offset += received
        }

        return data
    }
}

/// Server + verifier + policy. `spawnedPids` is read per connection so the pid-tree check
/// always sees the children alive right now.
final class SecretsBrokerServer: Sendable {
    let socketPath: URL
    let service: SecretsBrokerService
    private let verifier: any PeerVerifying
    private let spawnedPids: @Sendable () -> Set<pid_t>
    private let rejected: @Sendable (String) -> Void
    private let server: UnixSocketServer

    init(
        socketPath: URL,
        service: SecretsBrokerService,
        verifier: any PeerVerifying,
        spawnedPids: @escaping @Sendable () -> Set<pid_t>,
        rejected: @escaping @Sendable (String) -> Void = { _ in }
    ) {
        self.socketPath = socketPath
        self.service = service
        self.verifier = verifier
        self.spawnedPids = spawnedPids
        self.rejected = rejected

        server = UnixSocketServer(path: socketPath) { fd in
            defer { close(fd) }

            switch verifier.verify(socket: fd, spawned: spawnedPids()) {
            case .rejected(let reason):
                rejected(reason)
                // Nothing is read and nothing is written: an unverified peer learns nothing,
                // not even the protocol version.
                return

            case .accepted(let peer):
                guard let frame = UnixSocketServer.readFrame(fd) else {
                    return
                }

                UnixSocketServer.writeAll(fd, service.handle(frame: frame, peer: peer))
            }
        }
    }

    func start() throws {
        try server.start()
    }

    func stop() {
        server.stop()
    }
}
