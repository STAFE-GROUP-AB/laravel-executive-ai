// Install checkpoint (S1-05) — what the installer persists so a run can be resumed after the app
// quits or crashes, and so a half-done INSTALL_CORE can be rolled back.
//
// A JSON file in the user-data directory (`install/checkpoint.json`), one object, snake_case
// keys. It never contains a secret: no API key, no credential, no e-mail content. The owner's
// name and address are not stored either — only the fact that the owner exists.

import Foundation

struct InstallCheckpoint: Codable, Equatable, Sendable {
    static let currentSchemaVersion = 1

    struct PreflightSummary: Codable, Equatable, Sendable {
        /// The loopback port preflight found free (support matrix §6.6).
        var port: Int
        var language: PreflightLanguage
        var existingInstall: ExistingInstallRouting
        /// Checks that produced a warning; the Done-screen checklist reads these.
        var warnings: [PreflightCheckID]
        var fileVaultEnabled: Bool?
    }

    struct RuntimeProgress: Codable, Equatable, Sendable {
        var version: String
        /// Relative to `InstallPaths.runtimeRoot` while files are being staged.
        var stagingDirectoryName: String?
        /// True once `runtime/<version>/` exists and `current` points at it.
        var activated: Bool
    }

    /// Written by a data-preserving uninstall (S1-13, §12.2). The checkpoint that carries it has
    /// no completed state, so the next run starts at PREFLIGHT and installs the runtime again,
    /// while `owner_created` is carried over and CREATE_OWNER is skipped: the reinstall boots to
    /// the previous owner and the previous briefs in the untouched `data/`.
    struct UninstallMarker: Codable, Equatable, Sendable {
        var at: Date
        /// The app version that last wrote `data/`.
        var productVersion: String
        var ownerCreated: Bool

        enum CodingKeys: String, CodingKey {
            case at
            case productVersion = "product_version"
            case ownerCreated = "owner_created"
        }
    }

    var schemaVersion: Int
    var runID: UUID
    /// The last core state whose work finished; nil when nothing has completed.
    var lastCompletedState: InstallState?
    var updatedAt: Date
    var preflight: PreflightSummary?
    var runtime: RuntimeProgress?
    var ownerCreated: Bool
    var aiSelection: AiSelection?
    var uninstalledPreservingData: UninstallMarker?

    init(runID: UUID = UUID(), updatedAt: Date) {
        schemaVersion = Self.currentSchemaVersion
        self.runID = runID
        lastCompletedState = nil
        self.updatedAt = updatedAt
        preflight = nil
        runtime = nil
        ownerCreated = false
        aiSelection = nil
        uninstalledPreservingData = nil
    }

    /// The checkpoint a data-preserving uninstall leaves behind (installer.md §3).
    static func preservedData(ownerCreated: Bool, productVersion: String, at: Date) -> InstallCheckpoint {
        var checkpoint = InstallCheckpoint(updatedAt: at)
        checkpoint.ownerCreated = ownerCreated
        checkpoint.uninstalledPreservingData = UninstallMarker(at: at, productVersion: productVersion, ownerCreated: ownerCreated)

        return checkpoint
    }

    var isReady: Bool {
        lastCompletedState == .ready
    }

    /// A runtime directory that was started but never activated.
    var hasPartialRuntime: Bool {
        guard let runtime else {
            return false
        }

        return !runtime.activated
    }

    /// Where a resumed run continues.
    ///
    /// Security floor: nothing before a completed INSTALL_CORE is trusted across launches —
    /// the package is verified again (§6 "verify before execute") and preflight is cheap. After
    /// INSTALL_CORE the long work is done and the run continues at the next state.
    var resumeState: InstallState {
        guard let last = lastCompletedState, let index = last.coreIndex else {
            return .preflight
        }

        if index < InstallState.installCore.coreIndex! {
            return .preflight
        }

        return last.nextCore ?? .ready
    }

    enum CodingKeys: String, CodingKey {
        case schemaVersion = "schema_version"
        case runID = "run_id"
        case lastCompletedState = "last_completed_state"
        case updatedAt = "updated_at"
        case preflight
        case runtime
        case ownerCreated = "owner_created"
        case aiSelection = "ai_selection"
        case uninstalledPreservingData = "uninstalled_preserving_data"
    }
}

extension InstallCheckpoint.PreflightSummary {
    enum CodingKeys: String, CodingKey {
        case port
        case language
        case existingInstall = "existing_install"
        case warnings
        case fileVaultEnabled = "filevault_enabled"
    }
}

extension InstallCheckpoint.RuntimeProgress {
    enum CodingKeys: String, CodingKey {
        case version
        case stagingDirectoryName = "staging_directory"
        case activated
    }
}

protocol InstallCheckpointStore: Sendable {
    func load() throws -> InstallCheckpoint?
    func save(_ checkpoint: InstallCheckpoint) throws
    func clear() throws
}

enum InstallCheckpointError: Error, Equatable, Sendable {
    case unsupportedSchemaVersion(Int)
}

/// The JSON file in the user-data directory. Written atomically with 0600 permissions.
struct FileInstallCheckpointStore: InstallCheckpointStore {
    let url: URL

    func load() throws -> InstallCheckpoint? {
        guard FileManager.default.fileExists(atPath: url.path) else {
            return nil
        }

        let data = try Data(contentsOf: url)
        let checkpoint = try Self.decoder.decode(InstallCheckpoint.self, from: data)

        guard checkpoint.schemaVersion == InstallCheckpoint.currentSchemaVersion else {
            throw InstallCheckpointError.unsupportedSchemaVersion(checkpoint.schemaVersion)
        }

        return checkpoint
    }

    func save(_ checkpoint: InstallCheckpoint) throws {
        let directory = url.deletingLastPathComponent()
        try FileManager.default.createDirectory(at: directory, withIntermediateDirectories: true)

        let data = try Self.encoder.encode(checkpoint)
        try data.write(to: url, options: [.atomic])
        try FileManager.default.setAttributes([.posixPermissions: 0o600], ofItemAtPath: url.path)
    }

    func clear() throws {
        guard FileManager.default.fileExists(atPath: url.path) else {
            return
        }

        try FileManager.default.removeItem(at: url)
    }

    static var encoder: JSONEncoder {
        let encoder = JSONEncoder()
        encoder.outputFormatting = [.prettyPrinted, .sortedKeys, .withoutEscapingSlashes]
        encoder.dateEncodingStrategy = .iso8601

        return encoder
    }

    static var decoder: JSONDecoder {
        let decoder = JSONDecoder()
        decoder.dateDecodingStrategy = .iso8601

        return decoder
    }
}

/// Keeps the checkpoint in memory; tests and the DEBUG simulation.
final class InMemoryInstallCheckpointStore: InstallCheckpointStore, @unchecked Sendable {
    private let lock = NSLock()
    private var checkpoint: InstallCheckpoint?

    init(checkpoint: InstallCheckpoint? = nil) {
        self.checkpoint = checkpoint
    }

    func load() throws -> InstallCheckpoint? {
        lock.lock()
        defer { lock.unlock() }

        return checkpoint
    }

    func save(_ checkpoint: InstallCheckpoint) throws {
        lock.lock()
        defer { lock.unlock() }

        self.checkpoint = checkpoint
    }

    func clear() throws {
        lock.lock()
        defer { lock.unlock() }

        checkpoint = nil
    }
}
