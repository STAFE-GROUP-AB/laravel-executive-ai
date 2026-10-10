// Health probing per docs/architecture/health-contract.md: `/health/live` every 5 s decides
// whether the core is restarted; `/health/ready` every 30 s (with the `supervisor`-scoped token,
// ipc-contract §2) feeds the `queue_worker` and `scheduler_selftest` checks to the worker and
// scheduler states. The probe is a protocol so the supervisor's transitions are tested with a
// fake; `LoopbackHealthProbe` is the real client.

import Foundation

enum HealthCheckStatus: String, Codable, Sendable {
    case ok
    case fail
    case skip
}

struct ReadyProbeResult: Equatable, Sendable {
    let ready: Bool
    let checks: [String: HealthCheckStatus]
}

enum HealthProbeFailure: Error, Equatable, Sendable {
    case unreachable
    case invalidResponse
    /// The response signature did not verify (ipc-contract §4.5): treated as unreachable.
    case signatureInvalid
}

protocol HealthProbing: Sendable {
    func live() async -> Bool
    func ready() async throws -> ReadyProbeResult
}

/// Runs the two probe loops and feeds the supervisor until cancelled.
actor ServiceHealthMonitor {
    private let probe: any HealthProbing
    private let supervisor: ServiceSupervisor
    private let liveInterval: Duration
    private let readyInterval: Duration
    private let sleep: @Sendable (Duration) async throws -> Void

    init(
        probe: any HealthProbing,
        supervisor: ServiceSupervisor,
        liveInterval: Duration = .seconds(5),
        readyInterval: Duration = .seconds(30),
        sleep: @escaping @Sendable (Duration) async throws -> Void = { try await Task.sleep(for: $0) }
    ) {
        self.probe = probe
        self.supervisor = supervisor
        self.liveInterval = liveInterval
        self.readyInterval = readyInterval
        self.sleep = sleep
    }

    func run() async {
        let probe = self.probe
        let supervisor = self.supervisor
        let sleep = self.sleep
        let liveInterval = self.liveInterval
        let readyInterval = self.readyInterval

        await withTaskGroup(of: Void.self) { group in
            group.addTask {
                while !Task.isCancelled {
                    let ok = await probe.live()
                    await supervisor.observeLive(ok: ok)

                    guard (try? await sleep(liveInterval)) != nil else {
                        return
                    }
                }
            }

            group.addTask {
                while !Task.isCancelled {
                    if let result = try? await probe.ready() {
                        await supervisor.observeReady(checks: result.checks)
                    }

                    guard (try? await sleep(readyInterval)) != nil else {
                        return
                    }
                }
            }
        }
    }
}

/// The Supervisor's own grant (ipc-contract §3.1): derived from `K` at every start, lives in
/// memory for the process lifetime, scope `supervisor`.
struct SupervisorGrant: Sendable {
    let sessionId: String
    let token: String
    let signingKey: Data
    let expiresAt: Int

    static func derive(key: IpcKey, now: Date, random: (Int) -> Data = SecureRandom.bytes) -> SupervisorGrant {
        let sessionId = ULID.generate(now: now, random: random)
        // The Supervisor's grant lives for its process lifetime; a year outlives any uptime.
        let expiresAt = Int(now.timeIntervalSince1970) + 365 * 24 * 3600

        return SupervisorGrant(
            sessionId: sessionId,
            token: IpcSigning.token(key: key, client: .supervisor, sessionId: sessionId, expiresAt: expiresAt),
            signingKey: IpcSigning.signingKey(key: key, client: .supervisor, sessionId: sessionId),
            expiresAt: expiresAt
        )
    }
}

/// Real probe against `http://127.0.0.1:<port>` — the literal address, never `localhost`
/// (ipc-contract §1.1), no proxy, no cookies, no connectivity waiting.
final class LoopbackHealthProbe: HealthProbing, Sendable {
    let port: Int
    private let grant: SupervisorGrant
    private let session: URLSession
    private let now: @Sendable () -> Date
    private let verifyResponses: Bool

    init(port: Int, grant: SupervisorGrant, timeout: TimeInterval = 5, verifyResponses: Bool = true, now: @escaping @Sendable () -> Date = { Date() }) {
        self.port = port
        self.grant = grant
        self.now = now
        self.verifyResponses = verifyResponses

        let configuration = URLSessionConfiguration.ephemeral
        configuration.connectionProxyDictionary = [:]
        configuration.waitsForConnectivity = false
        configuration.timeoutIntervalForRequest = timeout
        configuration.timeoutIntervalForResource = timeout
        configuration.httpShouldSetCookies = false
        configuration.httpCookieAcceptPolicy = .never
        configuration.urlCache = nil
        configuration.requestCachePolicy = .reloadIgnoringLocalCacheData
        session = URLSession(configuration: configuration)
    }

    func live() async -> Bool {
        guard let url = URL(string: "http://127.0.0.1:\(port)/health/live") else {
            return false
        }

        var request = URLRequest(url: url)
        request.httpMethod = "GET"
        request.setValue("application/json", forHTTPHeaderField: "Accept")

        guard let (data, response) = try? await session.data(for: request),
              let http = response as? HTTPURLResponse, http.statusCode == 200,
              let object = try? JSONSerialization.jsonObject(with: data) as? [String: Any],
              object["status"] as? String == "ok"
        else {
            return false
        }

        return true
    }

    func ready() async throws -> ReadyProbeResult {
        let path = "/health/ready"

        guard let url = URL(string: "http://127.0.0.1:\(port)\(path)") else {
            throw HealthProbeFailure.unreachable
        }

        let timestamp = Int(now().timeIntervalSince1970)
        let nonce = IpcSigning.nonce()
        let signature = IpcSigning.signRequest(signingKey: grant.signingKey, method: "GET", pathAndQuery: path, timestamp: timestamp, nonce: nonce, body: Data())

        var request = URLRequest(url: url)
        request.httpMethod = "GET"
        request.setValue("application/json", forHTTPHeaderField: "Accept")
        request.setValue("Bearer \(grant.token)", forHTTPHeaderField: "Authorization")
        request.setValue("1", forHTTPHeaderField: "X-Lxai-Ipc-Version")
        request.setValue(String(timestamp), forHTTPHeaderField: "X-Lxai-Timestamp")
        request.setValue(nonce, forHTTPHeaderField: "X-Lxai-Nonce")
        request.setValue(signature, forHTTPHeaderField: "X-Lxai-Signature")
        request.setValue(ULID.generate(now: now()), forHTTPHeaderField: "X-Lxai-Request-Id")

        let data: Data
        let response: URLResponse

        do {
            (data, response) = try await session.data(for: request)
        } catch {
            throw HealthProbeFailure.unreachable
        }

        guard let http = response as? HTTPURLResponse else {
            throw HealthProbeFailure.invalidResponse
        }

        if verifyResponses {
            let signature = http.value(forHTTPHeaderField: "X-Lxai-Signature")

            guard IpcSigning.responseSignatureIsValid(signingKey: grant.signingKey, status: http.statusCode, nonce: nonce, body: data, signature: signature) else {
                throw HealthProbeFailure.signatureInvalid
            }
        }

        return try Self.parseReady(data)
    }

    /// The un-enveloped body of `/health/ready` (health contract §1; `200` and `503` carry the
    /// same shape), decoded once by `MenuBar/HealthDocument.swift` and reduced to what the
    /// supervisor reads.
    static func parseReady(_ data: Data) throws -> ReadyProbeResult {
        guard let document = try? HealthDocument.decode(data) else {
            throw HealthProbeFailure.invalidResponse
        }

        var statuses: [String: HealthCheckStatus] = [:]

        for check in document.checks {
            guard let status = HealthCheckStatus(rawValue: check.status) else {
                throw HealthProbeFailure.invalidResponse
            }

            statuses[check.name] = status
        }

        return ReadyProbeResult(ready: document.ready, checks: statuses)
    }
}
