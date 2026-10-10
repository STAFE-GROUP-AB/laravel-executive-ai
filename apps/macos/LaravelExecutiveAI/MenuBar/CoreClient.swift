// The core operations the panel calls (IPC contract §6.1–6.5), as a protocol (S1-09).
//
// The live loopback-HTTP client — token from the pairing grant, request/response signing, origin
// and version checks (ipc-contract §3–§5) — is S1-08 part 2 and must implement exactly this
// surface; docs/architecture/menubar.md §4 is the written form of it. This file ships two
// implementations that never touch the network: `FixtureCoreClient` (the frozen fixture pack,
// selectable per health state) and `UnreachableCoreClient` (the core is down).

import Foundation

enum CoreClientError: Error, Equatable, Sendable {
    /// No answer on loopback, or a non-JSON body (health contract §7 "Core unreachable").
    case unreachable(String)
    /// `404 not_found` — `briefs/latest` when `brief_state` is `none`.
    case notFound
    /// `403 forbidden_scope` or a policy denial (e.g. `resumeAi` while the broker is locked).
    case forbidden
    /// `426 version_unsupported` — the GUI is older than the core (ipc-contract §5.1).
    case versionUnsupported(serverMajor: Int)
    /// `429` (ipc-contract §9).
    case rateLimited
    case invalidResponse(String)
}

enum LatestBriefResponse: Equatable, Sendable {
    /// `304`: the cached `<id>:<version>` is current.
    case notModified(etag: String)
    /// `200`: the full brief document (brief.schema.json) and its ETag.
    case document(Data, etag: String)
}

/// One row of `GET /ipc/v1/approvals?state=pending` (ipc-contract §6.3). Read-only: the panel lists
/// and deep-links, it never approves or rejects (ADR-005 D1.6, §11).
struct PendingApproval: Codable, Equatable, Identifiable, Sendable {
    let id: String
    let kind: String
    let title: String
    let summary: String
    let createdAt: String
    let expiresAt: String
    let briefItemId: String?
    let openUrl: String
}

struct PendingApprovals: Codable, Equatable, Sendable {
    let items: [PendingApproval]
    let totalPending: Int
    let nextCursor: String?

    static let none = PendingApprovals(items: [], totalPending: 0, nextCursor: nil)
}

/// `data` of `POST /ipc/v1/ai/pause|resume` (ipc-contract §6.4).
struct AiActionsState: Codable, Equatable, Sendable {
    let aiActions: String
    let changedAt: String
    let pausedSince: String?
    let inFlightRunsCancelled: Int

    var isPaused: Bool {
        aiActions == "paused"
    }
}

/// `data` of `POST /ipc/v1/heartbeat` (ipc-contract §6.5).
struct HeartbeatAck: Codable, Equatable, Sendable {
    let receivedAt: String
    let nextHeartbeatInSeconds: Int
    let thresholdSeconds: Int
}

protocol CoreClient: Sendable {
    /// `http://127.0.0.1:<port>` — the loopback origin the PWA is served on, learned from the pairing
    /// grant (ipc-contract §1.1). "Open full brief" and "Open PWA" build their URLs on it.
    var origin: URL { get }

    /// §6.1 — the health contract's `ready` document, enveloped. Branch on `ready`, not status code.
    func health() async throws -> HealthDocument

    /// §6.2 — with `If-None-Match` when an ETag is cached. Never called on panel open (§14).
    func latestBrief(ifNoneMatch etag: String?) async throws -> LatestBriefResponse

    /// §6.3 — read-only list for the "requires approval" section.
    func pendingApprovals() async throws -> PendingApprovals

    /// §6.4 — idempotent; never requires elevation.
    func pauseAI() async throws -> AiActionsState

    /// §6.4 — may be denied (`forbidden`) while the automation broker is locked.
    func resumeAI() async throws -> AiActionsState

    /// §6.5 — every 30 s while the GUI runs; the only signal `menubar_integration` reads.
    func heartbeat(panelOpen: Bool) async throws -> HeartbeatAck
}

/// The core is not answering. The live build runs with this until S1-08 part 2 lands, so every
/// panel shows "Core unreachable" rather than pretending.
struct UnreachableCoreClient: CoreClient {
    let origin = URL(string: "http://127.0.0.1:8787")!
    let reason: String

    init(reason: String = "The IPC client arrives with S1-08 part 2.") {
        self.reason = reason
    }

    func health() async throws -> HealthDocument {
        throw CoreClientError.unreachable(reason)
    }

    func latestBrief(ifNoneMatch etag: String?) async throws -> LatestBriefResponse {
        throw CoreClientError.unreachable(reason)
    }

    func pendingApprovals() async throws -> PendingApprovals {
        throw CoreClientError.unreachable(reason)
    }

    func pauseAI() async throws -> AiActionsState {
        throw CoreClientError.unreachable(reason)
    }

    func resumeAI() async throws -> AiActionsState {
        throw CoreClientError.unreachable(reason)
    }

    func heartbeat(panelOpen: Bool) async throws -> HeartbeatAck {
        throw CoreClientError.unreachable(reason)
    }
}

/// The health states of `packaging/fixtures/health/` (health contract §8).
enum HealthFixture: String, CaseIterable, Sendable {
    case aiReady = "ready-ai-ready"
    case coreReadyNoAI = "ready-core-ready-no-ai"
    case staleBriefOffline = "ready-stale-brief-offline"
    case notReadySchedulerFailing = "not-ready-scheduler-failing"
}

/// Answers from the frozen fixture pack: one health state, one brief document, the pending
/// approvals fixture when the health state reports any. Reads `packaging/fixtures/` on disk —
/// tests and DEBUG runs only; it is never wired into a release build.
final class FixtureCoreClient: CoreClient, @unchecked Sendable {
    let origin = URL(string: "http://127.0.0.1:8787")!
    let fixtureRoot: URL

    private let lock = NSLock()
    private var healthFixture: HealthFixture
    private var briefFixture: String
    private var paused = false

    init(fixtureRoot: URL, health: HealthFixture = .aiReady, brief: String = "brief-canonical") {
        self.fixtureRoot = fixtureRoot
        healthFixture = health
        briefFixture = brief
    }

    /// `packaging/fixtures/` of the checkout this source file belongs to. CI checks out the whole
    /// repository, so the path resolves unsigned on the runner as it does locally.
    static func repositoryFixtureRoot(from file: String = #filePath) -> URL? {
        var directory = URL(fileURLWithPath: file).deletingLastPathComponent()

        for _ in 0..<12 {
            let candidate = directory.appending(path: "packaging").appending(path: "fixtures")

            if FileManager.default.fileExists(atPath: candidate.appending(path: "health.schema.json").path) {
                return candidate
            }

            directory = directory.deletingLastPathComponent()
        }

        return nil
    }

    func select(health: HealthFixture) {
        lock.withLock { healthFixture = health }
    }

    func select(brief: String) {
        lock.withLock { briefFixture = brief }
    }

    func health() async throws -> HealthDocument {
        let fixture = lock.withLock { healthFixture }

        return try HealthDocument.decode(try read("health/\(fixture.rawValue).json"))
    }

    func latestBrief(ifNoneMatch etag: String?) async throws -> LatestBriefResponse {
        let document = try read("briefs/\(lock.withLock { briefFixture }).json")
        let parsed = try JSONValue.parse(document)

        guard let id = parsed["id"]?.stringValue, let version = parsed["version"]?.intValue else {
            throw CoreClientError.invalidResponse("brief fixture without id/version")
        }

        let currentETag = "\"\(id):\(version)\""

        if etag == currentETag {
            return .notModified(etag: currentETag)
        }

        return .document(document, etag: currentETag)
    }

    func pendingApprovals() async throws -> PendingApprovals {
        let pending = try await health().pendingApprovals

        guard pending > 0 else {
            return .none
        }

        return try IpcFixture<PendingApprovals>.data(in: try read("ipc/approvals-pending.json"))
    }

    func pauseAI() async throws -> AiActionsState {
        lock.withLock { paused = true }

        return try IpcFixture<AiActionsState>.data(in: try read("ipc/ai-pause.json"))
    }

    func resumeAI() async throws -> AiActionsState {
        lock.withLock { paused = false }

        return try IpcFixture<AiActionsState>.data(in: try read("ipc/ai-resume.json"))
    }

    func heartbeat(panelOpen: Bool) async throws -> HeartbeatAck {
        try IpcFixture<HeartbeatAck>.data(in: try read("ipc/heartbeat.json"))
    }

    private func read(_ relativePath: String) throws -> Data {
        let url = fixtureRoot.appending(path: relativePath)

        do {
            return try Data(contentsOf: url)
        } catch {
            throw CoreClientError.unreachable("fixture \(relativePath) is missing")
        }
    }
}

/// `response.body.data` of a `packaging/fixtures/ipc/*.json` exchange. Only `data` leaves this
/// type; the recorded request headers (which carry the documented test token) are never read.
private struct IpcFixture<T: Decodable>: Decodable {
    struct Response: Decodable {
        struct Body: Decodable {
            let data: T
        }

        let body: Body
    }

    let response: Response

    static func data(in bytes: Data) throws -> T {
        let decoder = JSONDecoder()
        decoder.keyDecodingStrategy = .convertFromSnakeCase

        return try decoder.decode(IpcFixture<T>.self, from: bytes).response.body.data
    }
}
