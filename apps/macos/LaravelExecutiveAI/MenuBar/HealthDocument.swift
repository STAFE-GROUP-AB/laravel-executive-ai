// The `/health/ready` document (health contract 1.0.0, S0-14) as the menu bar reads it.
//
// `Install/HealthProbe.swift` deliberately carries only the subset the installer gates on
// (`ready`, failing check names, `ai_state`); the panel needs the whole §7 mapping input —
// `brief_state`, `latest_brief`, `connectivity`, `privacy_mode`, `pending_approvals` — so the full
// document is decoded here. `readiness` bridges to the installer's type so S1-08 part 2 can decode
// once and serve both consumers. Unknown keys are ignored (minor bumps add optional fields);
// unknown enum values decode to `.unknown` and are treated conservatively by `PanelModel`.

import Foundation

struct HealthDocument: Codable, Equatable, Sendable {
    enum AiState: String, Codable, Sendable {
        case notConfigured = "not_configured"
        case configuredUntested = "configured_untested"
        case ready
        case error
        case unknown

        init(from decoder: any Decoder) throws {
            self = AiState(rawValue: try decoder.singleValueContainer().decode(String.self)) ?? .unknown
        }
    }

    enum BriefState: String, Codable, Sendable {
        case none
        case fresh
        case stale
        case error
        case unknown

        init(from decoder: any Decoder) throws {
            self = BriefState(rawValue: try decoder.singleValueContainer().decode(String.self)) ?? .unknown
        }
    }

    enum Connectivity: String, Codable, Sendable {
        case online
        case offline
        case degraded
        case unknown

        init(from decoder: any Decoder) throws {
            self = Connectivity(rawValue: try decoder.singleValueContainer().decode(String.self)) ?? .unknown
        }
    }

    /// IPC contract §6.4 pause state. Not part of health 1.0.0; decoded when a later minor adds it
    /// (`ai_actions: "paused" | "active"`), otherwise the panel keeps the last pause/resume answer.
    enum AiActions: String, Codable, Sendable {
        case active
        case paused
        case unknown

        init(from decoder: any Decoder) throws {
            self = AiActions(rawValue: try decoder.singleValueContainer().decode(String.self)) ?? .unknown
        }
    }

    struct Check: Codable, Equatable, Sendable {
        let name: String
        let status: String
        let detail: String
        let checkedAt: String
        let durationMs: Int
        let ageSeconds: Int?
        let thresholdSeconds: Int?

        var isOk: Bool {
            status == "ok"
        }
    }

    struct LatestBrief: Codable, Equatable, Sendable {
        let id: String
        let version: Int
        let generatedAt: String
        let staleAfter: String

        /// IPC contract §6.2: `ETag: "<id>:<version>"`.
        var etag: String {
            "\"\(id):\(version)\""
        }
    }

    let schemaVersion: String
    let ready: Bool
    let checks: [Check]
    let aiReady: Bool
    let aiState: AiState
    let aiProvider: String?
    let aiLastSuccessAt: String?
    let briefState: BriefState
    let latestBrief: LatestBrief?
    let connectivity: Connectivity
    let privacyMode: String
    let pendingApprovals: Int
    let checkedAt: String
    let durationMs: Int
    let aiActions: AiActions?

    static func decode(_ data: Data) throws -> HealthDocument {
        let decoder = JSONDecoder()
        decoder.keyDecodingStrategy = .convertFromSnakeCase

        return try decoder.decode(HealthDocument.self, from: data)
    }

    var failingChecks: [Check] {
        checks.filter { !$0.isOk }
    }

    /// The installer's view of the same document (`Install/HealthProbe.swift`).
    var readiness: HealthReadiness {
        HealthReadiness(ready: ready, failingChecks: failingChecks.map(\.name), aiState: aiState.rawValue)
    }
}
