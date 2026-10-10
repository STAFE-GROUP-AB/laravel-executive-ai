// Shared doubles for the S1-08 part 2 client tests. The network is a stub `URLProtocol` that
// records what the client put on the wire and answers from the frozen fixture pack; the
// Supervisor is a scripted `SupervisorControlling`; the session store is in memory. Nothing here
// opens a socket, touches the Keychain or a Mach service. Every grant is derived from the
// documented test key (32 zero bytes, ipc-contract §4.6), which is never a valid production key.

import Foundation
import Synchronization
import XCTest
@testable import LaravelExecutiveAI

// MARK: - Fixtures

/// A `lxai1.` token split into its parts (ipc-contract §3.3).
struct IpcToken: Equatable {
    let client: IpcClient
    let sessionId: String
    let expiresAt: Int
    let mac: String

    static func parse(_ token: String) -> IpcToken? {
        let parts = token.split(separator: ".", omittingEmptySubsequences: false).map(String.init)

        guard parts.count == 5, parts[0] == "lxai1", let client = IpcClient(rawValue: parts[1]), let expiresAt = Int(parts[3]) else {
            return nil
        }

        return IpcToken(client: client, sessionId: parts[2], expiresAt: expiresAt, mac: parts[4])
    }
}

/// One recorded exchange of `packaging/fixtures/ipc/<name>.json`.
struct IpcFixture {
    let name: String
    let operation: String
    let method: String
    let path: String
    let requestHeaders: [String: String]
    let requestBody: JSONValue?
    let responseStatus: Int
    let responseHeaders: [String: String]
    let responseBody: JSONValue?

    static var directory: URL {
        TestFiles.repositoryRoot.appending(path: "packaging/fixtures/ipc")
    }

    static func all() throws -> [IpcFixture] {
        let names = try FileManager.default.contentsOfDirectory(atPath: directory.path).filter { $0.hasSuffix(".json") }.sorted()
        XCTAssertGreaterThanOrEqual(names.count, 16)

        return try names.map { try load(String($0.dropLast(5))) }
    }

    static func load(_ name: String) throws -> IpcFixture {
        let root = try JSONValue.parse(try Data(contentsOf: directory.appending(path: "\(name).json")))
        let request = try XCTUnwrap(root["request"], name)
        let response = try XCTUnwrap(root["response"], name)

        return IpcFixture(
            name: name,
            operation: try XCTUnwrap(root["operation"]?.stringValue, name),
            method: try XCTUnwrap(request["method"]?.stringValue, name),
            path: try XCTUnwrap(request["path"]?.stringValue, name),
            requestHeaders: Self.headers(request["headers"]),
            requestBody: request["body"].flatMap { $0.isNull ? nil : $0 },
            responseStatus: try XCTUnwrap(response["status"]?.intValue, name),
            responseHeaders: Self.headers(response["headers"]),
            responseBody: response["body"].flatMap { $0.isNull ? nil : $0 }
        )
    }

    private static func headers(_ value: JSONValue?) -> [String: String] {
        var headers: [String: String] = [:]

        for (name, entry) in value?.objectValue ?? [:] {
            if let text = entry.stringValue {
                headers[name] = text
            }
        }

        return headers
    }

    var token: IpcToken {
        IpcToken.parse(String((requestHeaders["Authorization"] ?? "").dropFirst("Bearer ".count)))!
    }

    var wireMajor: Int {
        Int(requestHeaders["X-Lxai-Ipc-Version"] ?? "") ?? 1
    }

    var timestamp: Int {
        Int(requestHeaders["X-Lxai-Timestamp"] ?? "") ?? 0
    }

    var date: Date {
        Date(timeIntervalSince1970: TimeInterval(timestamp))
    }

    var nonce: String {
        requestHeaders["X-Lxai-Nonce"] ?? ""
    }

    var requestId: String {
        requestHeaders["X-Lxai-Request-Id"] ?? ""
    }

    var errorCode: String? {
        responseBody?["error"]?["code"]?.stringValue
    }

    var signingKey: Data {
        IpcTestSupport.signingKey(client: token.client, sessionId: token.sessionId)
    }

    func requestBodyBytes() throws -> Data {
        guard let requestBody else {
            return Data()
        }

        return Data(try JCS.canonicalize(requestBody).utf8)
    }

    func responseBodyBytes() throws -> Data {
        guard let responseBody else {
            return Data()
        }

        return Data(try JCS.canonicalize(responseBody).utf8)
    }

    /// The operation the client must send to reproduce the recorded request.
    func ipcOperation() -> IpcOperation {
        IpcOperation(
            name: operation,
            method: method,
            pathAndQuery: path,
            body: requestBody,
            ifNoneMatch: requestHeaders["If-None-Match"],
            elevation: requestHeaders["X-Lxai-Elevation"]
        )
    }

    /// The recorded answer, byte for byte.
    func recordedReply() throws -> IpcStubURLProtocol.Reply {
        .response(status: responseStatus, headers: responseHeaders, body: try responseBodyBytes())
    }

    /// The recorded answer re-signed for the nonce the client actually used (the signature
    /// covers the request nonce, §4.5). Unsigned recordings stay unsigned.
    func reply(for request: URLRequest) throws -> IpcStubURLProtocol.Reply {
        var headers = responseHeaders
        let body = try responseBodyBytes()

        if headers["X-Lxai-Signature"] != nil, let nonce = request.value(forHTTPHeaderField: "X-Lxai-Nonce") {
            headers["X-Lxai-Signature"] = IpcSigning.signResponse(signingKey: signingKey, status: responseStatus, nonce: nonce, body: body)
        }

        return .response(status: responseStatus, headers: headers, body: body)
    }
}

// MARK: - Grants, replies, clients

enum IpcTestSupport {
    /// The fixtures' session (ipc-contract §4.6).
    static let fixtureSessionId = "01M4G0QK2T6Z5P9Y3R7W1A8C4E"
    static let fixtureExpiresAt = 1_794_240_000
    static let fixtureNow = Date(timeIntervalSince1970: 1_791_648_005)
    static let port = 8787

    static func signingKey(client: IpcClient = .menubar, sessionId: String = fixtureSessionId) -> Data {
        IpcSigning.signingKey(key: IpcKey.fixtureKey, client: client, sessionId: sessionId)
    }

    /// A grant under the test key for the fixtures' session, or any other session id.
    static func grant(client: IpcClient = .menubar, sessionId: String = fixtureSessionId, expiresAt: Int = fixtureExpiresAt, port: Int = port) -> PairingGrant {
        PairingGrant(
            v: 1,
            client: client,
            sessionId: sessionId,
            token: IpcSigning.token(key: IpcKey.fixtureKey, client: client, sessionId: sessionId, expiresAt: expiresAt),
            signingKey: IpcSigning.base64url(signingKey(client: client, sessionId: sessionId)),
            issuedAt: Date(timeIntervalSince1970: TimeInterval(expiresAt) - PairingGrant.lifetime),
            expiresAt: Date(timeIntervalSince1970: TimeInterval(expiresAt)),
            port: port,
            ipcVersion: 1
        )
    }

    /// The §8.1 envelope around `data`, in canonical form.
    static func successBody(data: JSONValue, requestId: String = "01M4G06BJDY51YZX0PWRBQ8EBJ") throws -> Data {
        Data(try JCS.canonicalize(.object([
            "ok": .bool(true),
            "request_id": .string(requestId),
            "server_time": .string("2026-10-10T18:00:05+02:00"),
            "data": data,
        ])).utf8)
    }

    /// The §8.2 envelope, in canonical form.
    static func errorBody(code: String, message: String = "fixed sentence", hint: String? = nil, retryAfterSeconds: Int? = nil, supportedVersions: [Int]? = nil, details: [String: String]? = nil, serverTime: String = "2026-10-10T18:00:05+02:00", requestId: String = "01M4G0B4BKK6EN4EZ6NEA4323Y") throws -> Data {
        Data(try JCS.canonicalize(.object([
            "ok": .bool(false),
            "request_id": .string(requestId),
            "server_time": .string(serverTime),
            "error": .object([
                "code": .string(code),
                "message": .string(message),
                "hint": hint.map(JSONValue.string) ?? .null,
                "retry_after_seconds": retryAfterSeconds.map { JSONValue.number(Double($0)) } ?? .null,
                "supported_versions": supportedVersions.map { JSONValue.array($0.map { .number(Double($0)) }) } ?? .null,
                "details": details.map { JSONValue.object($0.mapValues(JSONValue.string)) } ?? .null,
            ]),
        ])).utf8)
    }

    /// A reply signed over (status, the request's nonce, body) with the session's key (§4.5).
    static func signedReply(for request: URLRequest, status: Int, body: Data, signingKey: Data = signingKey(), extraHeaders: [String: String] = [:], port: Int = port) -> IpcStubURLProtocol.Reply {
        let nonce = request.value(forHTTPHeaderField: "X-Lxai-Nonce") ?? ""
        var headers = [
            "Content-Type": "application/json; charset=utf-8",
            "Cache-Control": "no-store, private",
            "X-Lxai-Ipc-Version": "1",
            "X-Lxai-Request-Id": request.value(forHTTPHeaderField: "X-Lxai-Request-Id") ?? "01M4G06BJDY51YZX0PWRBQ8EBJ",
            "X-Lxai-Origin": "127.0.0.1:\(port)",
            "X-Lxai-Signature": IpcSigning.signResponse(signingKey: signingKey, status: status, nonce: nonce, body: body),
        ]

        for (name, value) in extraHeaders {
            headers[name] = value
        }

        return .response(status: status, headers: headers, body: body)
    }

    /// An answer the server sends before it has a key (§4.5): no signature.
    static func unsignedReply(status: Int, body: Data, extraHeaders: [String: String] = [:]) -> IpcStubURLProtocol.Reply {
        var headers = [
            "Content-Type": "application/json; charset=utf-8",
            "Cache-Control": "no-store, private",
            "X-Lxai-Ipc-Version": "1",
            "X-Lxai-Origin": "127.0.0.1:\(port)",
        ]

        for (name, value) in extraHeaders {
            headers[name] = value
        }

        return .response(status: status, headers: headers, body: body)
    }

    static func healthFixtureData(_ fixture: HealthFixture) throws -> JSONValue {
        try JSONValue.parse(try Data(contentsOf: TestFiles.repositoryRoot.appending(path: "packaging/fixtures/health/\(fixture.rawValue).json")))
    }

    /// A stand-in core: signed, shape-correct answers for every panel operation — the health
    /// fixture, a heartbeat ack, pause/resume states, an empty approvals page, `404` for the brief.
    static func panelServer(health: HealthFixture = .aiReady, signingKey: Data = signingKey(), port: Int = port) throws -> IpcStubURLProtocol.Handler {
        let healthBody = try successBody(data: try healthFixtureData(health))
        let heartbeat = try successBody(data: .object([
            "received_at": .string("2026-10-10T18:00:05+02:00"),
            "next_heartbeat_in_seconds": .number(30),
            "threshold_seconds": .number(120),
            "server": .object(["app_version": .string("1.0.0"), "runtime_version": .string("1.0.0")]),
        ]))
        let paused = try successBody(data: .object(["ai_actions": .string("paused"), "changed_at": .string("2026-10-10T18:02:11+02:00"), "paused_since": .string("2026-10-10T18:02:11+02:00"), "in_flight_runs_cancelled": .number(0)]))
        let active = try successBody(data: .object(["ai_actions": .string("active"), "changed_at": .string("2026-10-10T18:03:11+02:00"), "paused_since": .null, "in_flight_runs_cancelled": .number(0)]))
        let approvals = try successBody(data: .object(["items": .array([]), "total_pending": .number(0), "next_cursor": .null]))
        let notFound = try errorBody(code: "not_found")

        return { request, _ in
            switch request.url?.path() {
            case "/ipc/v1/health": signedReply(for: request, status: 200, body: healthBody, signingKey: signingKey, port: port)
            case "/ipc/v1/heartbeat": signedReply(for: request, status: 200, body: heartbeat, signingKey: signingKey, port: port)
            case "/ipc/v1/ai/pause": signedReply(for: request, status: 200, body: paused, signingKey: signingKey, port: port)
            case "/ipc/v1/ai/resume": signedReply(for: request, status: 200, body: active, signingKey: signingKey, port: port)
            case "/ipc/v1/approvals": signedReply(for: request, status: 200, body: approvals, signingKey: signingKey, port: port)
            case "/ipc/v1/briefs/latest": signedReply(for: request, status: 404, body: notFound, signingKey: signingKey, port: port)
            default: .failure(.badURL)
            }
        }
    }

    /// A session preloaded with `grant` (or empty) against a scripted Supervisor.
    static func session(grant: PairingGrant? = grant(), control: StubSupervisorControl = StubSupervisorControl(), store: InMemorySessionStore? = nil, now: Date = fixtureNow) -> IpcSession {
        IpcSession(store: store ?? InMemorySessionStore(grant: grant), control: control, now: { now })
    }

    /// A client whose network is the stub protocol. `now`, `nonce` and `requestId` are fixed
    /// when given so a request reproduces a recording byte for byte.
    static func client(
        port: Int = port,
        session: IpcSession,
        now: Date = fixtureNow,
        nonce: String? = nil,
        nonces: NonceSequence? = nil,
        requestId: String? = nil,
        wireMajor: Int = 1,
        clientVersion: String = "1.0.0 (120)",
        since: String = "2026-10-10T17:30:00+02:00",
        sink: any IpcEventSink = NoopIpcSink()
    ) -> LoopbackIpcClient {
        var configuration = LoopbackIpcClient.Configuration(port: port, clientVersion: clientVersion, since: since)
        configuration.wireMajor = wireMajor
        configuration.configureSession = { $0.protocolClasses = [IpcStubURLProtocol.self] }

        let sequence = nonces

        return LoopbackIpcClient(
            configuration: configuration,
            session: session,
            now: { now },
            nonce: { nonce ?? sequence?.next() ?? IpcSigning.nonce() },
            requestId: { requestId ?? ULID.generate() },
            sink: sink
        )
    }
}

/// Deterministic nonces, one per request, so a test can tell retries apart.
final class NonceSequence: Sendable {
    private let counter = Mutex<Int>(0)

    func next() -> String {
        let index = counter.withLock { value -> Int in
            value += 1
            return value
        }

        return IpcSigning.base64url(Data(repeating: UInt8(truncatingIfNeeded: index), count: 16))
    }
}

// MARK: - Doubles

/// Scripted `pair` answers (the last one repeats); every other call is recorded and succeeds.
final class StubSupervisorControl: SupervisorControlling, Sendable {
    private let pairAnswers: Mutex<[Result<PairingGrant, SupervisorControlError>]>
    private let recorded = Mutex<[String]>([])

    init(pair: [Result<PairingGrant, SupervisorControlError>] = [.success(IpcTestSupport.grant())]) {
        precondition(!pair.isEmpty)
        pairAnswers = Mutex(pair)
    }

    var calls: [String] {
        recorded.withLock { $0 }
    }

    var pairCalls: Int {
        calls.filter { $0 == "pair" }.count
    }

    func pair(client: IpcClient) async throws -> PairingGrant {
        recorded.withLock { $0.append("pair") }
        let next = pairAnswers.withLock { answers -> Result<PairingGrant, SupervisorControlError> in
            answers.count > 1 ? answers.removeFirst() : answers[0]
        }

        return try next.get()
    }

    func resetPairing() async throws -> PairingGrant {
        recorded.withLock { $0.append("resetPairing") }

        return try await pair(client: .menubar)
    }

    func services() async throws -> [ServiceStatus] {
        recorded.withLock { $0.append("services") }

        return []
    }

    func restart(service: ServiceName) async throws {
        recorded.withLock { $0.append("restart") }
    }

    func stopAll() async throws {
        recorded.withLock { $0.append("stopAll") }
    }

    func startAll() async throws {
        recorded.withLock { $0.append("startAll") }
    }

    func elevate(sessionId: String, operation: String, nonce: String) async throws -> Elevation {
        recorded.withLock { $0.append("elevate") }

        return Elevation(value: IpcSigning.elevation(key: IpcKey.fixtureKey, sessionId: sessionId, operation: operation, nonce: nonce, expiresAt: 1_791_648_180), operation: operation, nonce: nonce, expiresAt: Date(timeIntervalSince1970: 1_791_648_180))
    }

    func storeProviderApiKey(provider: String, apiKey: TransientSecret) async throws {
        recorded.withLock { $0.append("storeProviderApiKey") }
    }

    func removeAllSecrets() async throws -> [KeychainItemRef] {
        recorded.withLock { $0.append("removeAllSecrets") }

        return []
    }
}

/// Keeps every log event in memory, for the redaction assertions.
final class CapturingIpcSink: IpcEventSink, Sendable {
    private let stored = Mutex<[IpcLogEvent]>([])

    var events: [IpcLogEvent] {
        stored.withLock { $0 }
    }

    var text: String {
        events.map(\.description).joined(separator: "\n")
    }

    func record(_ event: IpcLogEvent) {
        stored.withLock { $0.append(event) }
    }
}

// MARK: - The stubbed network

/// Answers `URLSession` from a handler and records every request the session handed over,
/// including the body bytes it would have put on the wire.
final class IpcStubURLProtocol: URLProtocol {
    enum Reply: Sendable {
        case response(status: Int, headers: [String: String], body: Data)
        /// A connection-level failure (nothing listening on the port).
        case failure(URLError.Code)
    }

    struct Recorded: Sendable {
        let request: URLRequest
        let body: Data

        func header(_ name: String) -> String? {
            request.value(forHTTPHeaderField: name)
        }
    }

    typealias Handler = @Sendable (URLRequest, Data) -> Reply

    private struct State: Sendable {
        var handler: Handler?
        var requests: [Recorded] = []
    }

    private static let state = Mutex<State>(State())

    static func install(_ handler: @escaping Handler) {
        state.withLock { $0 = State(handler: handler, requests: []) }
    }

    static func reset() {
        state.withLock { $0 = State() }
    }

    static var requests: [Recorded] {
        state.withLock { $0.requests }
    }

    override class func canInit(with request: URLRequest) -> Bool {
        true
    }

    override class func canonicalRequest(for request: URLRequest) -> URLRequest {
        request
    }

    override func startLoading() {
        let body = Self.body(of: request)
        let recorded = Recorded(request: request, body: body)
        let handler = Self.state.withLock { state -> Handler? in
            state.requests.append(recorded)
            return state.handler
        }

        guard let handler, let url = request.url else {
            client?.urlProtocol(self, didFailWithError: URLError(.cannotConnectToHost))
            return
        }

        switch handler(request, body) {
        case .failure(let code):
            client?.urlProtocol(self, didFailWithError: URLError(code))
        case .response(let status, let headers, let data):
            let response = HTTPURLResponse(url: url, statusCode: status, httpVersion: "HTTP/1.1", headerFields: headers)!

            if (300...399).contains(status), status != 304, let location = headers["Location"], let target = URL(string: location, relativeTo: url) {
                // The session consults its delegate; the client's delegate refuses, so the 3xx
                // itself is delivered as the final answer.
                client?.urlProtocol(self, wasRedirectedTo: URLRequest(url: target), redirectResponse: response)
            }

            client?.urlProtocol(self, didReceive: response, cacheStoragePolicy: .notAllowed)
            client?.urlProtocol(self, didLoad: data)
            client?.urlProtocolDidFinishLoading(self)
        }
    }

    override func stopLoading() {}

    private static func body(of request: URLRequest) -> Data {
        if let body = request.httpBody {
            return body
        }

        guard let stream = request.httpBodyStream else {
            return Data()
        }

        stream.open()

        defer {
            stream.close()
        }

        var data = Data()
        let size = 4096
        var buffer = [UInt8](repeating: 0, count: size)

        while stream.hasBytesAvailable {
            let count = stream.read(&buffer, maxLength: size)

            guard count > 0 else {
                break
            }

            data.append(buffer, count: count)
        }

        return data
    }
}

// MARK: - Assertions

/// Headers a native client never sends (ipc-contract §4.1, §5.2) and the `X-Lxai-*` allow list.
enum IpcHeaderRules {
    static let forbidden = ["origin", "referer", "cookie", "forwarded", "x-http-method-override"]
    static let forbiddenPrefixes = ["x-forwarded-", "tailscale-user-", "sec-fetch-"]
    static let allowedLxai: Set<String> = ["x-lxai-ipc-version", "x-lxai-timestamp", "x-lxai-nonce", "x-lxai-signature", "x-lxai-request-id", "x-lxai-elevation"]

    static func assertClean(_ request: URLRequest, _ message: String = "", file: StaticString = #filePath, line: UInt = #line) {
        for name in (request.allHTTPHeaderFields ?? [:]).keys.map({ $0.lowercased() }) {
            XCTAssertFalse(forbidden.contains(name), "\(message): forbidden header \(name)", file: file, line: line)
            XCTAssertFalse(forbiddenPrefixes.contains { name.hasPrefix($0) }, "\(message): forbidden header \(name)", file: file, line: line)

            if name.hasPrefix("x-lxai-") {
                XCTAssertTrue(allowedLxai.contains(name), "\(message): unknown X-Lxai header \(name)", file: file, line: line)
            }
        }
    }
}

/// Runs `body` and returns its typed failure, failing the test on any other error.
func ipcResult<T>(_ body: () async throws -> T, file: StaticString = #filePath, line: UInt = #line) async -> Result<T, IpcClientError> {
    do {
        return .success(try await body())
    } catch let error as IpcClientError {
        return .failure(error)
    } catch {
        XCTFail("unexpected error \(error)", file: file, line: line)
        return .failure(.unreachable("unexpected"))
    }
}

func coreResult<T>(_ body: () async throws -> T, file: StaticString = #filePath, line: UInt = #line) async -> Result<T, CoreClientError> {
    do {
        return .success(try await body())
    } catch let error as CoreClientError {
        return .failure(error)
    } catch {
        XCTFail("unexpected error \(error)", file: file, line: line)
        return .failure(.unreachable("unexpected"))
    }
}

extension Result {
    var failureValue: Failure? {
        guard case .failure(let error) = self else {
            return nil
        }

        return error
    }

    var successValue: Success? {
        guard case .success(let value) = self else {
            return nil
        }

        return value
    }
}
