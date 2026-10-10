// SELECT_AI (S1-05): the choice on screen 5, nothing more.
//
// Three neutral options (§12.3 screen 5): skip, a local inference runtime that is already on
// this Mac, or the person's own API key. The key itself is never part of `AiSelection`: storing
// it is the Supervisor's job (ADR-003) and is wired in later. It travels once, in a
// `TransientSecret` that cannot be encoded, printed or persisted.

import Foundation

/// A local inference runtime found on this Mac. Listed without preselection (§12.3).
struct LocalAiRuntime: Codable, Equatable, Sendable, Hashable, Identifiable {
    /// Stable identifier, e.g. `ollama`. Used as the adapter id towards the core.
    let id: String
    let displayName: String

    static let ollama = LocalAiRuntime(id: "ollama", displayName: "Ollama")
    static let lmStudio = LocalAiRuntime(id: "lmstudio", displayName: "LM Studio")
}

/// Remote providers the key screen offers, by adapter id (ADR-004 provider neutrality).
enum AiProviderID: String, Codable, CaseIterable, Sendable, Hashable {
    case openAI = "openai"
    case anthropic = "anthropic"
    case mistral = "mistral"
    case custom = "custom"

    var displayName: String {
        switch self {
        case .openAI: return "OpenAI"
        case .anthropic: return "Anthropic"
        case .mistral: return "Mistral"
        case .custom: return "Other OpenAI-compatible endpoint"
        }
    }
}

enum AiSelection: Equatable, Sendable, Hashable {
    case skip
    case detectedLocalRuntime(LocalAiRuntime)
    case ownApiKey(AiProviderID)

    /// What the Done screen says when this is the choice and no completion has succeeded yet.
    var setupRequired: Bool {
        if case .skip = self {
            return true
        }

        return false
    }
}

extension AiSelection: Codable {
    private enum CodingKeys: String, CodingKey {
        case kind
        case runtime
        case provider
    }

    private enum Kind: String, Codable {
        case skip
        case localRuntime = "local_runtime"
        case ownApiKey = "own_api_key"
    }

    init(from decoder: any Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)

        switch try container.decode(Kind.self, forKey: .kind) {
        case .skip:
            self = .skip
        case .localRuntime:
            self = .detectedLocalRuntime(try container.decode(LocalAiRuntime.self, forKey: .runtime))
        case .ownApiKey:
            self = .ownApiKey(try container.decode(AiProviderID.self, forKey: .provider))
        }
    }

    func encode(to encoder: any Encoder) throws {
        var container = encoder.container(keyedBy: CodingKeys.self)

        switch self {
        case .skip:
            try container.encode(Kind.skip, forKey: .kind)
        case .detectedLocalRuntime(let runtime):
            try container.encode(Kind.localRuntime, forKey: .kind)
            try container.encode(runtime, forKey: .runtime)
        case .ownApiKey(let provider):
            try container.encode(Kind.ownApiKey, forKey: .kind)
            try container.encode(provider, forKey: .provider)
        }
    }
}

/// A secret that exists only to be handed on. Not Codable, not Equatable, redacted when printed.
struct TransientSecret: Sendable, CustomStringConvertible, CustomDebugStringConvertible {
    private let value: String

    init?(_ value: String) {
        let trimmed = value.trimmingCharacters(in: .whitespacesAndNewlines)

        guard !trimmed.isEmpty else {
            return nil
        }

        self.value = trimmed
    }

    /// The only way to read it: the consumer receives the value inside a closure and must not
    /// retain it.
    func use<T>(_ body: (String) throws -> T) rethrows -> T {
        try body(value)
    }

    var description: String { "TransientSecret(<redacted>)" }
    var debugDescription: String { description }
}

/// What screen 5 submits.
struct AiSelectionSubmission: Sendable {
    let selection: AiSelection
    /// Present only for `.ownApiKey`. Handed to the Supervisor's secrets broker by the
    /// `AiSelectionApplying` implementation; never stored by the installer.
    let apiKey: TransientSecret?

    init(selection: AiSelection, apiKey: TransientSecret? = nil) {
        self.selection = selection
        self.apiKey = apiKey
    }

    func validate() -> AiSelectionError? {
        if case .ownApiKey = selection, apiKey == nil {
            return .apiKeyMissing
        }

        return nil
    }
}

enum AiSelectionError: Error, Equatable, Sendable {
    case apiKeyMissing
    case unavailable(String)
    case rejected(String)
}

/// Applies the choice to the core (provider selection) and, for a key, hands it to the secrets
/// broker. Real implementation arrives with the Supervisor (S1-07) and the AI adapters.
protocol AiSelectionApplying: Sendable {
    func apply(_ submission: AiSelectionSubmission) async throws
}

/// Accepts `skip` (nothing to do) and refuses anything that would need the Supervisor.
struct SkipOnlyAiSelectionApplier: AiSelectionApplying {
    func apply(_ submission: AiSelectionSubmission) async throws {
        guard case .skip = submission.selection else {
            throw AiSelectionError.unavailable("Provider selection and key storage arrive with the Supervisor (S1-07).")
        }
    }
}

final class ScriptedAiSelectionApplier: AiSelectionApplying, @unchecked Sendable {
    private let lock = NSLock()
    private let error: AiSelectionError?
    private(set) var applied: [AiSelection] = []

    init(error: AiSelectionError? = nil) {
        self.error = error
    }

    func apply(_ submission: AiSelectionSubmission) async throws {
        if let error {
            throw error
        }

        lock.withLock {
            applied.append(submission.selection)
        }
    }
}

/// Finds inference runtimes already installed on this Mac. No network, no process launch.
protocol LocalAiRuntimeDetecting: Sendable {
    func detect() async -> [LocalAiRuntime]
}

/// Looks for the well-known app bundles in `/Applications` and `~/Applications`. The list is
/// alphabetical and nothing is preselected (§12.3 screen 5).
struct FileSystemLocalAiRuntimeDetector: LocalAiRuntimeDetecting {
    private let candidates: [(runtime: LocalAiRuntime, bundleNames: [String])] = [
        (.ollama, ["Ollama.app"]),
        (.lmStudio, ["LM Studio.app"]),
    ]

    let searchDirectories: [URL]

    init(fileManager: FileManager = .default) {
        searchDirectories = [
            URL(fileURLWithPath: "/Applications", isDirectory: true),
            fileManager.homeDirectoryForCurrentUser.appending(path: "Applications"),
        ]
    }

    func detect() async -> [LocalAiRuntime] {
        candidates
            .filter { candidate in
                candidate.bundleNames.contains { name in
                    searchDirectories.contains { directory in
                        FileManager.default.fileExists(atPath: directory.appending(path: name).path)
                    }
                }
            }
            .map(\.runtime)
            .sorted { $0.displayName < $1.displayName }
    }
}

struct StaticLocalAiRuntimeDetector: LocalAiRuntimeDetecting {
    let runtimes: [LocalAiRuntime]

    func detect() async -> [LocalAiRuntime] {
        runtimes
    }
}
