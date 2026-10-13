// The GUI's end of ipc-contract §1–§6 and §8–§9 (S1-08 part 2): `CoreClient` over loopback HTTP.
//
// Mirror image of the server's `IpcAuthenticate` + `IpcResponder` (docs/architecture/ipc-server.md
// §5). Every request goes to the literal `http://127.0.0.1:<port>` — never `localhost`, no proxy,
// no cookies, no cache, no redirect — with the §4.1 header set, the body in RFC 8785 canonical
// form and the §4.2 signature over exactly those bytes. Every answer is verified against the §4.5
// response signature **before** its body is parsed; a missing or wrong signature is a connection
// failure, never data. The four answers the server sends unsigned because it had no key
// (`session_invalid`, `origin_rejected`, `version_unsupported`, `secrets_unavailable`) are acted
// on — re-pair, diagnostics, "Update required", "Core unreachable" — never rendered.
//
// The model and the view never see a header: `PanelModel` gets `HealthDocument`, bytes of a brief,
// `PendingApprovals`, `AiActionsState`, `HeartbeatAck` or a `CoreClientError`, nothing else.

import Foundation
import os
import Synchronization

/// The §8.2 error table, typed. Cases carry fixed server sentences or counters, never a header.
enum IpcClientError: Error, Equatable, Sendable {
    /// No answer on loopback, a non-HTTP answer, a missing or invalid response signature, an
    /// unsigned answer that may not arrive unsigned, or a body that is not the §8 envelope.
    case unreachable(String)
    /// The Supervisor could not pair the GUI (no control channel, refused).
    case supervisorUnreachable(String)
    /// `401 session_invalid` after one silent re-pair.
    case sessionInvalid
    /// `401 signature_invalid` after one silent re-pair.
    case signatureInvalid
    /// `401 timestamp_skew` after one clock resync from `server_time`.
    case timestampSkew
    /// `401 nonce_replayed` after one retry with a fresh nonce.
    case nonceReplayed
    /// `426`, or a response wire major above the one this build implements (§5.1).
    case versionUnsupported(serverMajor: Int, supportedVersions: [Int], hint: String?)
    /// `403 origin_rejected` — never retried; surfaces in diagnostics.
    case originRejected
    /// `403 forbidden_scope` — a bug: the wrong client on a route.
    case forbiddenScope
    /// `403 elevation_required` / `elevation_invalid` (§7.3).
    case elevationRequired
    /// `403 policy_denied` — the fixed message is shown.
    case policyDenied(message: String)
    /// `403 reauth_required` — open `details.open_url` in the PWA.
    case reauthRequired(openUrl: String?)
    case notFound
    /// `409 state_conflict`.
    case stateConflict
    /// `409 owner_exists` — the §6.9 refusal: this Mac already has an owner.
    /// Not a failure for the installer: it is how a data-preserving
    /// reinstall learns that CREATE_OWNER has nothing left to do.
    case ownerExists
    /// `400`, `413`, `415` — bugs; logged, never retried.
    case badRequest(code: String)
    /// `422 validation_failed` / `oauth_state_invalid`: field → problem.
    case validationFailed(code: String, details: [String: String])
    /// `429`; `Retry-After` honoured by the caller.
    case rateLimited(retryAfterSeconds: Int?)
    /// `503 secrets_unavailable`: the core could not read `K` (broker down or locked).
    case secretsUnavailable(hint: String?, retryAfterSeconds: Int?)
    /// `500 internal`; the request id is the correlation key for diagnostics.
    case internalError(requestId: String?)
    /// A 3xx other than 304. The client never follows a redirect.
    case redirected(status: Int)
    /// An error envelope with a code this build does not know.
    case unexpected(status: Int, code: String?)

    /// What the panel's `CoreClientError` surface can express (menubar.md §4).
    var coreClientError: CoreClientError {
        switch self {
        case .unreachable(let reason):
            .unreachable(reason)
        case .supervisorUnreachable(let reason):
            .unreachable("the Supervisor did not pair the menu bar: \(reason)")
        case .sessionInvalid:
            .unreachable("the IPC session is invalid after re-pairing")
        case .signatureInvalid:
            .unreachable("the core rejected the request signature after re-pairing")
        case .timestampSkew:
            .unreachable("the core and the menu bar disagree about the time")
        case .nonceReplayed:
            .unreachable("the core rejected a fresh nonce")
        case .secretsUnavailable:
            .unreachable("the core could not read its pairing key")
        case .redirected(let status):
            .unreachable("the core answered with a redirect (\(status))")
        case .versionUnsupported(let serverMajor, _, _):
            .versionUnsupported(serverMajor: serverMajor)
        case .originRejected, .forbiddenScope, .elevationRequired, .policyDenied, .reauthRequired:
            .forbidden
        case .notFound:
            .notFound
        case .rateLimited:
            .rateLimited
        case .stateConflict, .ownerExists, .badRequest, .validationFailed, .internalError, .unexpected:
            .invalidResponse(userFacingReason)
        }
    }

    /// Short, sanitised, fit for the panel and the installer. Never a header, never a token.
    var userFacingReason: String {
        switch self {
        case .unreachable(let reason): "core unreachable: \(reason)"
        case .supervisorUnreachable(let reason): "pairing failed: \(reason)"
        case .sessionInvalid: "session invalid"
        case .signatureInvalid: "request signature rejected"
        case .timestampSkew: "clock skew"
        case .nonceReplayed: "nonce replayed"
        case .versionUnsupported(let serverMajor, let supported, _): "IPC version unsupported (core speaks \(serverMajor); supports \(supported.map(String.init).joined(separator: ",")))"
        case .originRejected: "origin rejected"
        case .forbiddenScope: "forbidden scope"
        case .elevationRequired: "elevation required"
        case .policyDenied(let message): message
        case .reauthRequired: "re-authentication required"
        case .notFound: "not found"
        case .stateConflict: "state conflict"
        case .ownerExists: "an owner account already exists on this Mac"
        case .badRequest(let code): "bad request (\(code))"
        case .validationFailed(let code, _): "validation failed (\(code))"
        case .rateLimited(let seconds): "rate limited\(seconds.map { " for \($0) s" } ?? "")"
        case .secretsUnavailable: "the core could not read its pairing key"
        case .internalError: "internal error"
        case .redirected(let status): "redirect (\(status))"
        case .unexpected(let status, let code): "unexpected answer (\(status) \(code ?? "-"))"
        }
    }
}

/// One request to the core: method, request-target, canonical body and the per-operation extras.
struct IpcOperation: Equatable, Sendable {
    /// ipc-contract §5.1. The wire carries only the major (`wireMajor`); this
    /// full semver is what the heartbeat reports. 1.1.0 added §6.9's
    /// `POST /ipc/v1/owner` and the `owner_exists` code.
    static let contractVersion = "1.1.0"

    var name: String
    var method: String
    var pathAndQuery: String
    var body: JSONValue?
    var ifNoneMatch: String?
    /// `X-Lxai-Elevation` for `remote-access/enable` (§7.3); the nonce of that one request.
    var elevation: String?
    /// ipc-contract §9 client timeout.
    var timeout: TimeInterval

    init(name: String, method: String, pathAndQuery: String, body: JSONValue? = nil, ifNoneMatch: String? = nil, elevation: String? = nil, timeout: TimeInterval = 5) {
        self.name = name
        self.method = method
        self.pathAndQuery = pathAndQuery
        self.body = body
        self.ifNoneMatch = ifNoneMatch
        self.elevation = elevation
        self.timeout = timeout
    }

    /// The bytes that go on the wire and under the signature: RFC 8785 canonical JSON, or nothing.
    func bodyBytes() throws -> Data {
        guard let body else {
            return Data()
        }

        return Data(try JCS.canonicalize(body).utf8)
    }

    // §6 operations the panel and the installer use.

    static let health = IpcOperation(name: "health", method: "GET", pathAndQuery: "/ipc/v1/health", timeout: 6)
    static let healthReady = IpcOperation(name: "health/ready", method: "GET", pathAndQuery: "/health/ready", timeout: 6)
    static let pendingApprovals = IpcOperation(name: "approvals", method: "GET", pathAndQuery: "/ipc/v1/approvals?state=pending&limit=20")
    static let aiPause = IpcOperation(name: "ai/pause", method: "POST", pathAndQuery: "/ipc/v1/ai/pause", body: .object(["reason": .string("user_menubar")]))
    static let aiResume = IpcOperation(name: "ai/resume", method: "POST", pathAndQuery: "/ipc/v1/ai/resume", body: .object([:]))

    /// §6.9 CREATE_OWNER. The draft carries the two fields screen 4 asks for;
    /// the zone and the language are the Mac's, so the first brief is
    /// scheduled and written in the environment the person is sitting in. No
    /// workspace name is sent (the screen does not ask) and no credential
    /// exists to send: sign-in is a one-time code by mail.
    static func createOwner(_ draft: OwnerDraft, timeZone: String, language: String) -> IpcOperation {
        IpcOperation(
            name: "owner",
            method: "POST",
            pathAndQuery: "/ipc/v1/owner",
            body: .object([
                "name": .string(draft.trimmedName),
                "email": .string(draft.trimmedEmail),
                "timezone": .string(timeZone),
                "language": .string(language),
            ]),
            // §9: a 10 s server budget (up to 5 s waiting on
            // `lea:create-owner`, then one transaction), 12 s on the client.
            timeout: 12
        )
    }

    static func latestBrief(ifNoneMatch etag: String?) -> IpcOperation {
        IpcOperation(name: "briefs/latest", method: "GET", pathAndQuery: "/ipc/v1/briefs/latest", ifNoneMatch: etag)
    }

    /// §6.5. `since` is the GUI's start time (RFC 3339); `quitting` is sent only by "Quit UI".
    static func heartbeat(clientVersion: String, since: String, panelOpen: Bool, quitting: Bool = false) -> IpcOperation {
        var body: [String: JSONValue] = [
            "client": .string("menubar"),
            "client_version": .string(clientVersion),
            "contract_version": .string(contractVersion),
            "panel_open": .bool(panelOpen),
            "since": .string(since),
        ]

        if quitting {
            body["quitting"] = .bool(true)
        }

        return IpcOperation(name: "heartbeat", method: "POST", pathAndQuery: "/ipc/v1/heartbeat", body: .object(body), timeout: 3)
    }
}

/// A verified answer: the signature checked (or legitimately absent), redirects refused, the
/// §8.2 errors already mapped. `body` is empty (304) or a JSON object that is not an error
/// envelope: the §8.1 success envelope on `/ipc/v1/*`, the bare health document on `/health/ready`.
struct IpcResponse: Sendable, CustomStringConvertible, CustomDebugStringConvertible {
    let status: Int
    let body: Data
    let requestNonce: String
    let signed: Bool
    private let headers: [String: String]

    /// Status and size only: the headers hold the signature and the nonce, which never reach a
    /// log or an error (ipc-contract §10).
    var description: String {
        "IpcResponse(status: \(status), signed: \(signed), bytes: \(body.count))"
    }

    var debugDescription: String {
        description
    }

    init(status: Int, headers: [String: String], body: Data, requestNonce: String, signed: Bool) {
        self.status = status
        self.body = body
        self.requestNonce = requestNonce
        self.signed = signed
        self.headers = Dictionary(headers.map { ($0.key.lowercased(), $0.value) }, uniquingKeysWith: { first, _ in first })
    }

    func header(_ name: String) -> String? {
        headers[name.lowercased()]
    }

    /// `data` of the §8.1 envelope.
    func envelopeData() throws -> JSONValue {
        guard let object = try? JSONValue.parse(body), object["ok"]?.boolValue == true, let data = object["data"] else {
            throw IpcClientError.unreachable("the answer is not the IPC success envelope")
        }

        return data
    }

    /// `data` re-encoded as JSON bytes, for the typed decoders.
    func envelopeDataBytes() throws -> Data {
        try JSONEncoder().encode(try envelopeData())
    }
}

/// What the client logs: operation name, status and outcome. No header value can reach it.
struct IpcLogEvent: Equatable, Sendable, CustomStringConvertible {
    let operation: String
    let status: Int?
    let outcome: String
    let requestId: String
    let durationMs: Int

    var description: String {
        "ipc \(operation) status=\(status.map(String.init) ?? "-") outcome=\(outcome) request_id=\(requestId) duration_ms=\(durationMs)"
    }
}

protocol IpcEventSink: Sendable {
    func record(_ event: IpcLogEvent)
}

/// `os.Logger`, subsystem `<prefix>.app`, category `ipc`; the request id is `.private`
/// (ADR-003 D7 row 6), the rest are literals or enum names.
struct OSLogIpcSink: IpcEventSink {
    private let logger: Logger

    init(subsystem: String) {
        logger = Logger(subsystem: subsystem, category: "ipc")
    }

    func record(_ event: IpcLogEvent) {
        logger.info("ipc \(event.operation, privacy: .public) status=\(event.status ?? 0, privacy: .public) outcome=\(event.outcome, privacy: .public) request_id=\(event.requestId, privacy: .private) duration_ms=\(event.durationMs, privacy: .public)")
    }
}

struct NoopIpcSink: IpcEventSink {
    func record(_ event: IpcLogEvent) {}
}

/// Refuses every redirect (ipc-contract §1.1: the client talks to one origin and nothing else).
private final class NoRedirectDelegate: NSObject, URLSessionTaskDelegate, Sendable {
    func urlSession(_ session: URLSession, task: URLSessionTask, willPerformHTTPRedirection response: HTTPURLResponse, newRequest request: URLRequest, completionHandler: @escaping @Sendable (URLRequest?) -> Void) {
        completionHandler(nil)
    }
}

final class LoopbackIpcClient: CoreClient, Sendable {
    /// The wire major this build implements (§5.1).
    static let wireMajor = 1
    /// Unsigned answers the client may act on (§4.5): the server had no signing key yet.
    static let unsignedErrorCodes: Set<String> = ["session_invalid", "origin_rejected", "version_unsupported", "secrets_unavailable"]

    struct Configuration: Sendable {
        var port: Int
        /// `"1.0.0 (120)"` — `CFBundleShortVersionString (CFBundleVersion)`, sent in heartbeats.
        var clientVersion: String
        /// RFC 3339 start time of this GUI process, sent in heartbeats (§6.5).
        var since: String
        /// What goes into `X-Lxai-Ipc-Version`. Only a conformance test changes it.
        var wireMajor = LoopbackIpcClient.wireMajor
        /// Tests only: registers a stub `URLProtocol` on the session configuration.
        var configureSession: (@Sendable (URLSessionConfiguration) -> Void)?

        init(port: Int, clientVersion: String, since: String) {
            self.port = port
            self.clientVersion = clientVersion
            self.since = since
        }
    }

    let origin: URL
    let configuration: Configuration
    private let session: IpcSession
    private let urlSession: URLSession
    private let now: @Sendable () -> Date
    private let makeNonce: @Sendable () -> String
    private let makeRequestId: @Sendable () -> String
    private let sink: any IpcEventSink
    /// `server_time − local time` after a `timestamp_skew` answer (§8.2).
    private let clockOffset = Mutex<TimeInterval>(0)

    init(
        configuration: Configuration,
        session: IpcSession,
        now: @escaping @Sendable () -> Date = { Date() },
        nonce: @escaping @Sendable () -> String = { IpcSigning.nonce() },
        requestId: @escaping @Sendable () -> String = { ULID.generate() },
        sink: any IpcEventSink = NoopIpcSink()
    ) {
        precondition((1...65535).contains(configuration.port), "loopback port out of range")

        self.configuration = configuration
        self.session = session
        self.now = now
        makeNonce = nonce
        makeRequestId = requestId
        self.sink = sink
        origin = URL(string: "http://127.0.0.1:\(configuration.port)")!

        let sessionConfiguration = Self.makeURLSessionConfiguration()
        configuration.configureSession?(sessionConfiguration)
        urlSession = URLSession(configuration: sessionConfiguration, delegate: NoRedirectDelegate(), delegateQueue: nil)
    }

    /// ipc-contract §9: ephemeral, no proxy, no cookies, no cache, no connectivity waiting, one
    /// keep-alive connection. `IpcOriginClientTest` pins every field.
    static func makeURLSessionConfiguration() -> URLSessionConfiguration {
        let configuration = URLSessionConfiguration.ephemeral
        configuration.connectionProxyDictionary = [:]
        configuration.waitsForConnectivity = false
        configuration.allowsCellularAccess = false
        configuration.allowsExpensiveNetworkAccess = false
        configuration.allowsConstrainedNetworkAccess = false
        configuration.timeoutIntervalForRequest = 35
        configuration.timeoutIntervalForResource = 35
        configuration.httpShouldSetCookies = false
        configuration.httpCookieAcceptPolicy = .never
        configuration.httpCookieStorage = nil
        configuration.urlCache = nil
        configuration.requestCachePolicy = .reloadIgnoringLocalCacheData
        configuration.httpMaximumConnectionsPerHost = 1
        configuration.httpShouldUsePipelining = false

        return configuration
    }

    var port: Int {
        configuration.port
    }

    // MARK: - CoreClient (ipc-contract §6.1–§6.5)

    func health() async throws -> HealthDocument {
        try await core {
            let response = try await send(.health)
            let bytes = try response.envelopeDataBytes()

            guard let document = try? HealthDocument.decode(bytes) else {
                throw CoreClientError.invalidResponse("health: not a health document")
            }

            return document
        }
    }

    func latestBrief(ifNoneMatch etag: String?) async throws -> LatestBriefResponse {
        try await core {
            let response = try await send(.latestBrief(ifNoneMatch: etag))

            if response.status == 304 {
                guard let current = response.header("ETag") ?? etag else {
                    throw CoreClientError.invalidResponse("briefs/latest: 304 without an ETag")
                }

                return .notModified(etag: current)
            }

            guard let current = response.header("ETag") else {
                throw CoreClientError.invalidResponse("briefs/latest: no ETag")
            }

            return .document(try response.envelopeDataBytes(), etag: current)
        }
    }

    func pendingApprovals() async throws -> PendingApprovals {
        try await core {
            try Self.decode(PendingApprovals.self, from: try await send(.pendingApprovals), operation: "approvals")
        }
    }

    func pauseAI() async throws -> AiActionsState {
        try await core {
            try Self.decode(AiActionsState.self, from: try await send(.aiPause), operation: "ai/pause")
        }
    }

    func resumeAI() async throws -> AiActionsState {
        try await core {
            try Self.decode(AiActionsState.self, from: try await send(.aiResume), operation: "ai/resume")
        }
    }

    func heartbeat(panelOpen: Bool) async throws -> HeartbeatAck {
        try await heartbeat(panelOpen: panelOpen, quitting: false)
    }

    /// §6.8: "Quit UI" may send one last heartbeat with `quitting: true`.
    func heartbeat(panelOpen: Bool, quitting: Bool) async throws -> HeartbeatAck {
        try await core {
            let operation = IpcOperation.heartbeat(clientVersion: configuration.clientVersion, since: configuration.since, panelOpen: panelOpen, quitting: quitting)

            return try Self.decode(HeartbeatAck.self, from: try await send(operation), operation: "heartbeat")
        }
    }

    /// `GET /health/live` — outside the contract, no token, no version header (health contract
    /// §6). True only for `200` with `status: ok`.
    func live() async -> Bool {
        var request = URLRequest(url: origin.appending(path: "health/live"))
        request.httpMethod = "GET"
        request.timeoutInterval = 5
        request.setValue("application/json", forHTTPHeaderField: "Accept")

        guard let (data, response) = try? await urlSession.data(for: request),
              let http = response as? HTTPURLResponse, http.statusCode == 200,
              let object = try? JSONValue.parse(data), object["status"]?.stringValue == "ok"
        else {
            return false
        }

        return true
    }

    // MARK: - The signed exchange

    /// One operation, with the §8.2 single retries: `session_invalid` / `signature_invalid` →
    /// re-pair once; `timestamp_skew` → resync from `server_time` once; `nonce_replayed` → a
    /// fresh nonce once. Everything else surfaces as-is. A `nonce` is given only for an elevated
    /// request (the elevation is bound to it), which is therefore never retried.
    func send(_ operation: IpcOperation, nonce fixedNonce: String? = nil) async throws -> IpcResponse {
        var grant = try await currentGrant()
        var attempt = 0

        while true {
            attempt += 1
            let response = try await perform(operation, grant: grant, nonce: fixedNonce ?? makeNonce())

            switch Self.outcome(of: response) {
            case .success:
                return response
            case .failure(let error):
                throw error
            case .retry(let error, let recovery):
                guard attempt == 1, fixedNonce == nil else {
                    throw error
                }

                switch recovery {
                case .repair:
                    grant = try await replaceInvalid(grant)
                case .resyncClock(let serverTime):
                    if let serverTime {
                        clockOffset.withLock { $0 = serverTime.timeIntervalSince(now()) }
                    }
                case .freshNonce:
                    break
                }
            }
        }
    }

    private func perform(_ operation: IpcOperation, grant: PairingGrant, nonce: String) async throws -> IpcResponse {
        guard grant.port == configuration.port else {
            throw IpcClientError.unreachable("the pairing grant names another port")
        }

        guard let signingKey = grant.signingKeyData, signingKey.count == IpcKey.byteCount else {
            throw IpcClientError.unreachable("the pairing grant carries no signing key")
        }

        let started = ContinuousClock.now
        let requestId = makeRequestId()
        let body = try operation.bodyBytes()
        let timestamp = Int((now().timeIntervalSince1970 + clockOffset.withLock { $0 }).rounded(.down))
        let signature = IpcSigning.signRequest(signingKey: signingKey, method: operation.method, pathAndQuery: operation.pathAndQuery, timestamp: timestamp, nonce: nonce, body: body)

        guard let url = URL(string: "http://127.0.0.1:\(configuration.port)\(operation.pathAndQuery)") else {
            throw IpcClientError.unreachable("malformed request target")
        }

        var request = URLRequest(url: url)
        request.httpMethod = operation.method
        request.timeoutInterval = operation.timeout
        request.httpShouldHandleCookies = false
        request.cachePolicy = .reloadIgnoringLocalCacheData
        request.setValue("application/json", forHTTPHeaderField: "Accept")
        request.setValue("Bearer \(grant.token)", forHTTPHeaderField: "Authorization")
        request.setValue(String(configuration.wireMajor), forHTTPHeaderField: "X-Lxai-Ipc-Version")
        request.setValue(String(timestamp), forHTTPHeaderField: "X-Lxai-Timestamp")
        request.setValue(nonce, forHTTPHeaderField: "X-Lxai-Nonce")
        request.setValue(signature, forHTTPHeaderField: "X-Lxai-Signature")
        request.setValue(requestId, forHTTPHeaderField: "X-Lxai-Request-Id")

        if let etag = operation.ifNoneMatch {
            request.setValue(etag, forHTTPHeaderField: "If-None-Match")
        }

        if let elevation = operation.elevation {
            request.setValue(elevation, forHTTPHeaderField: "X-Lxai-Elevation")
        }

        if !body.isEmpty {
            request.setValue("application/json", forHTTPHeaderField: "Content-Type")
            request.httpBody = body
        }

        let data: Data
        let urlResponse: URLResponse

        do {
            (data, urlResponse) = try await urlSession.data(for: request)
        } catch {
            log(operation, status: nil, outcome: "transport_failure", requestId: requestId, started: started)
            throw IpcClientError.unreachable("no answer on loopback")
        }

        guard let http = urlResponse as? HTTPURLResponse else {
            log(operation, status: nil, outcome: "not_http", requestId: requestId, started: started)
            throw IpcClientError.unreachable("not an HTTP answer")
        }

        let headers = Dictionary(http.allHeaderFields.compactMap { key, value -> (String, String)? in
            guard let name = key as? String, let text = value as? String else {
                return nil
            }

            return (name, text)
        }, uniquingKeysWith: { first, _ in first })
        let signatureHeader = http.value(forHTTPHeaderField: "X-Lxai-Signature")

        // §4.5: verify before parsing. The body is not looked at until the signature holds, or
        // until the answer is one of the four the server sends before it has a key.
        let signed: Bool

        if let signatureHeader {
            guard IpcSigning.responseSignatureIsValid(signingKey: signingKey, status: http.statusCode, nonce: nonce, body: data, signature: signatureHeader) else {
                log(operation, status: http.statusCode, outcome: "signature_invalid", requestId: requestId, started: started)
                throw IpcClientError.unreachable("the response signature did not verify")
            }

            signed = true
        } else {
            guard Self.isAcceptableUnsigned(status: http.statusCode, body: data) else {
                log(operation, status: http.statusCode, outcome: "unsigned", requestId: requestId, started: started)
                throw IpcClientError.unreachable("the response carried no signature")
            }

            signed = false
        }

        if let major = http.value(forHTTPHeaderField: "X-Lxai-Ipc-Version") {
            guard let serverMajor = Int(major) else {
                log(operation, status: http.statusCode, outcome: "version_unparsable", requestId: requestId, started: started)
                throw IpcClientError.unreachable("the response version header is malformed")
            }

            if serverMajor > Self.wireMajor {
                log(operation, status: http.statusCode, outcome: "version_above_client", requestId: requestId, started: started)
                throw IpcClientError.versionUnsupported(serverMajor: serverMajor, supportedVersions: [], hint: nil)
            }
        }

        log(operation, status: http.statusCode, outcome: signed ? "signed" : "unsigned_error", requestId: requestId, started: started)

        return IpcResponse(status: http.statusCode, headers: headers, body: data, requestNonce: nonce, signed: signed)
    }

    /// §4.5: `401`, `403`, `426` and `503` may arrive unsigned only with the matching code.
    static func isAcceptableUnsigned(status: Int, body: Data) -> Bool {
        guard [401, 403, 426, 503].contains(status), let envelope = ErrorEnvelope.decode(body) else {
            return false
        }

        return unsignedErrorCodes.contains(envelope.code)
    }

    private enum Recovery: Equatable {
        case repair
        case resyncClock(serverTime: Date?)
        case freshNonce
    }

    private enum Outcome {
        case success
        case failure(IpcClientError)
        case retry(IpcClientError, Recovery)
    }

    /// Maps a verified answer to the §8.2 table.
    private static func outcome(of response: IpcResponse) -> Outcome {
        if response.status == 304 {
            return .success
        }

        if (300...399).contains(response.status) {
            return .failure(.redirected(status: response.status))
        }

        guard let envelope = ErrorEnvelope.decode(response.body) else {
            if (200...299).contains(response.status) || response.status == 503 {
                // 2xx envelopes, and `503` health answers (`ok: true`, health contract §1).
                return .success
            }

            return .failure(.unexpected(status: response.status, code: nil))
        }

        switch envelope.code {
        case "session_invalid":
            return .retry(.sessionInvalid, .repair)
        case "signature_invalid":
            return .retry(.signatureInvalid, .repair)
        case "timestamp_skew":
            return .retry(.timestampSkew, .resyncClock(serverTime: envelope.serverTime))
        case "nonce_replayed":
            return .retry(.nonceReplayed, .freshNonce)
        case "version_unsupported":
            // The highest major the core supports is the one the GUI would have to speak.
            return .failure(.versionUnsupported(serverMajor: envelope.supportedVersions.max() ?? Self.wireMajor, supportedVersions: envelope.supportedVersions, hint: envelope.hint))
        case "origin_rejected":
            return .failure(.originRejected)
        case "forbidden_scope":
            return .failure(.forbiddenScope)
        case "elevation_required", "elevation_invalid":
            return .failure(.elevationRequired)
        case "policy_denied":
            return .failure(.policyDenied(message: envelope.message))
        case "reauth_required":
            return .failure(.reauthRequired(openUrl: envelope.details["open_url"]))
        case "not_found":
            return .failure(.notFound)
        case "state_conflict":
            return .failure(.stateConflict)
        case "owner_exists":
            return .failure(.ownerExists)
        case "bad_request", "payload_too_large", "unsupported_media_type":
            return .failure(.badRequest(code: envelope.code))
        case "validation_failed", "oauth_state_invalid":
            return .failure(.validationFailed(code: envelope.code, details: envelope.details))
        case "rate_limited":
            return .failure(.rateLimited(retryAfterSeconds: envelope.retryAfterSeconds ?? response.header("Retry-After").flatMap(Int.init)))
        case "secrets_unavailable":
            return .failure(.secretsUnavailable(hint: envelope.hint, retryAfterSeconds: envelope.retryAfterSeconds ?? response.header("Retry-After").flatMap(Int.init)))
        case "internal":
            return .failure(.internalError(requestId: envelope.requestId))
        default:
            return .failure(.unexpected(status: response.status, code: envelope.code))
        }
    }

    // MARK: - Session plumbing

    private func currentGrant() async throws -> PairingGrant {
        do {
            return try await session.currentGrant()
        } catch let error as IpcSessionError {
            throw IpcClientError.supervisorUnreachable(error.userFacingReason)
        }
    }

    private func replaceInvalid(_ grant: PairingGrant) async throws -> PairingGrant {
        do {
            return try await session.replaceInvalid(grant)
        } catch let error as IpcSessionError {
            throw IpcClientError.supervisorUnreachable(error.userFacingReason)
        }
    }

    private func core<T>(_ body: () async throws -> T) async throws -> T {
        do {
            return try await body()
        } catch let error as IpcClientError {
            throw error.coreClientError
        } catch let error as CoreClientError {
            throw error
        } catch {
            throw CoreClientError.invalidResponse("the answer could not be decoded")
        }
    }

    private static func decode<T: Decodable>(_ type: T.Type, from response: IpcResponse, operation: String) throws -> T {
        let decoder = JSONDecoder()
        decoder.keyDecodingStrategy = .convertFromSnakeCase
        let bytes = try response.envelopeDataBytes()

        guard let value = try? decoder.decode(T.self, from: bytes) else {
            throw CoreClientError.invalidResponse("\(operation): unexpected data shape")
        }

        return value
    }

    private func log(_ operation: IpcOperation, status: Int?, outcome: String, requestId: String, started: ContinuousClock.Instant) {
        let elapsed = ContinuousClock.now - started
        let milliseconds = Int(elapsed.components.seconds * 1000 + elapsed.components.attoseconds / 1_000_000_000_000_000)

        sink.record(IpcLogEvent(operation: operation.name, status: status, outcome: outcome, requestId: requestId, durationMs: milliseconds))
    }
}

/// The §8.2 error envelope, decoded leniently (unknown keys ignored). Only the fixed fields leave it.
struct ErrorEnvelope: Equatable, Sendable {
    let code: String
    let message: String
    let hint: String?
    let retryAfterSeconds: Int?
    let supportedVersions: [Int]
    let details: [String: String]
    let requestId: String?
    let serverTime: Date?

    static func decode(_ body: Data) -> ErrorEnvelope? {
        guard let object = try? JSONValue.parse(body), object["ok"]?.boolValue == false, let error = object["error"], let code = error["code"]?.stringValue else {
            return nil
        }

        var details: [String: String] = [:]

        for (key, value) in error["details"]?.objectValue ?? [:] {
            if let text = value.stringValue {
                details[key] = text
            }
        }

        return ErrorEnvelope(
            code: code,
            message: error["message"]?.stringValue ?? "",
            hint: error["hint"]?.stringValue,
            retryAfterSeconds: error["retry_after_seconds"]?.intValue,
            supportedVersions: error["supported_versions"]?.arrayValue?.compactMap(\.intValue) ?? [],
            details: details,
            requestId: object["request_id"]?.stringValue,
            serverTime: object["server_time"]?.stringValue.flatMap { RFC3339.parse($0)?.date }
        )
    }
}

extension JSONValue {
    var boolValue: Bool? {
        guard case .bool(let value) = self else {
            return nil
        }

        return value
    }
}

extension ServiceState {
    /// ipc-contract §7.2 wrote `running|starting|stopped|degraded` for `ServiceStatus.state`;
    /// the Supervisor reports `healthy` for the running-and-ok case and adds `failed` for the
    /// post-bound state (services.md §11). The client maps rather than the contract changing:
    /// `healthy` → `running`; `failed` has no contract name and is carried through as itself.
    var ipcContractName: String {
        switch self {
        case .healthy: "running"
        case .starting, .degraded, .stopped, .failed: rawValue
        }
    }
}
