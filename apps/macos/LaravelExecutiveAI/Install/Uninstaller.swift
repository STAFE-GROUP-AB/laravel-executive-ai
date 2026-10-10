// Uninstall (S1-13; blueprint §12.2, §1.1 Settings → Uninstall; ADR-003 D8, threat model T-41,
// T-72). The complete order, each step idempotent, progress journaled so a crash mid-way
// resumes without repeating anything:
//
//   1. stopAll             the Supervisor stops the three PHP children (XPC, ipc-contract §7.2)
//   2. removeAllSecrets    the Supervisor deletes every se.stafe.executive-ai.* Keychain item
//                          (XPC). Only the Supervisor holds the access group (ADR-003 D2), so
//                          the sweep runs while it is still alive and is reported back by name
//   3. unregister          SMAppService.unregister(): launchd cannot respawn a Supervisor that
//                          would recreate the items
//   4. RuntimeUninstaller  runtime/, run/, Caches, Logs; data/ unless the person keeps it
//   5. BriefCache.clear()  belt and braces — the cache lives under Caches
//   6. install/            keep: a marker checkpoint carrying owner_created, so a reinstall
//                          boots to the previous owner and briefs (installer.md §3);
//                          delete: the directory and the now-empty Application Support directory
//   7. the app bundle      moved to the Trash last, through `AppBundleRemoving`
//
// Steps 1–3 are the ones a retry must never repeat blindly (after 3 the Supervisor is gone), so
// each is journaled in `install/uninstall.json` before the next starts and before anything on
// disk is touched. Steps 4–7 are idempotent on their own. The journal holds step names, the data
// choice and counts — never a secret, a path outside the layout, or a person's name.

import AppKit
import Foundation
import Synchronization

// MARK: - Choice, steps, journal

/// §12.2: "an explicit choice to keep or delete user data".
enum UninstallDataChoice: String, Codable, CaseIterable, Sendable {
    case keep
    case delete

    var preservesData: Bool {
        self == .keep
    }
}

enum UninstallStep: String, Codable, CaseIterable, Sendable {
    case servicesStopped = "services_stopped"
    case secretsRemoved = "secrets_removed"
    case agentUnregistered = "agent_unregistered"
    case runtimeRemoved = "runtime_removed"
    case briefCacheCleared = "brief_cache_cleared"
    case installDirectoryFinalized = "install_directory_finalized"
    case appBundleTrashed = "app_bundle_trashed"
}

/// What happened to the Keychain items. `skipped` is honest about the one case the sweep cannot
/// run: the Supervisor is not registered as a launch agent, so nothing holds the access group.
struct SecretsSweep: Codable, Equatable, Sendable {
    enum Status: String, Codable, Sendable {
        case removed
        case skipped
    }

    var status: Status
    var removedCount: Int
    var skippedReason: String?

    static func removed(count: Int) -> SecretsSweep {
        SecretsSweep(status: .removed, removedCount: count, skippedReason: nil)
    }

    static func skipped(reason: String) -> SecretsSweep {
        SecretsSweep(status: .skipped, removedCount: 0, skippedReason: reason)
    }

    enum CodingKeys: String, CodingKey {
        case status
        case removedCount = "removed_count"
        case skippedReason = "skipped_reason"
    }
}

/// `install/uninstall.json`, the same JSON style as the checkpoint (snake_case, ISO-8601).
struct UninstallJournal: Codable, Equatable, Sendable {
    static let currentSchemaVersion = 1

    var schemaVersion: Int
    var runID: UUID
    var startedAt: Date
    var updatedAt: Date
    var dataChoice: UninstallDataChoice
    var completed: [UninstallStep]
    /// Carried from the install checkpoint at the start, so the keep-data marker can be
    /// written even after the checkpoint itself is gone.
    var ownerCreated: Bool
    var productVersion: String
    var secretsSweep: SecretsSweep?

    init(runID: UUID = UUID(), startedAt: Date, dataChoice: UninstallDataChoice, ownerCreated: Bool, productVersion: String) {
        schemaVersion = Self.currentSchemaVersion
        self.runID = runID
        self.startedAt = startedAt
        updatedAt = startedAt
        self.dataChoice = dataChoice
        completed = []
        self.ownerCreated = ownerCreated
        self.productVersion = productVersion
        secretsSweep = nil
    }

    func isDone(_ step: UninstallStep) -> Bool {
        completed.contains(step)
    }

    enum CodingKeys: String, CodingKey {
        case schemaVersion = "schema_version"
        case runID = "run_id"
        case startedAt = "started_at"
        case updatedAt = "updated_at"
        case dataChoice = "data_choice"
        case completed
        case ownerCreated = "owner_created"
        case productVersion = "product_version"
        case secretsSweep = "secrets_sweep"
    }
}

protocol UninstallJournalStore: Sendable {
    func load() throws -> UninstallJournal?
    func save(_ journal: UninstallJournal) throws
    func clear() throws
}

/// The JSON file next to the checkpoint. Written atomically with 0600 permissions.
struct FileUninstallJournalStore: UninstallJournalStore {
    let url: URL

    private struct SchemaProbe: Decodable {
        let schemaVersion: Int

        enum CodingKeys: String, CodingKey {
            case schemaVersion = "schema_version"
        }
    }

    /// A journal of another schema version is ignored, never trusted: every step is idempotent,
    /// so starting over is always safe. A corrupt file throws; the orchestrator treats that the
    /// same way.
    func load() throws -> UninstallJournal? {
        guard FileManager.default.fileExists(atPath: url.path) else {
            return nil
        }

        let data = try Data(contentsOf: url)

        guard try FileInstallCheckpointStore.decoder.decode(SchemaProbe.self, from: data).schemaVersion == UninstallJournal.currentSchemaVersion else {
            return nil
        }

        return try FileInstallCheckpointStore.decoder.decode(UninstallJournal.self, from: data)
    }

    func save(_ journal: UninstallJournal) throws {
        try FileManager.default.createDirectory(at: url.deletingLastPathComponent(), withIntermediateDirectories: true)
        try FileInstallCheckpointStore.encoder.encode(journal).write(to: url, options: [.atomic])
        try FileManager.default.setAttributes([.posixPermissions: 0o600], ofItemAtPath: url.path)
    }

    func clear() throws {
        guard FileManager.default.fileExists(atPath: url.path) else {
            return
        }

        try FileManager.default.removeItem(at: url)
    }
}

/// Tests and the DEBUG simulation.
final class InMemoryUninstallJournalStore: UninstallJournalStore {
    private let journal = Mutex<UninstallJournal?>(nil)

    init(journal initial: UninstallJournal? = nil) {
        journal.withLock { $0 = initial }
    }

    func load() throws -> UninstallJournal? {
        journal.withLock { $0 }
    }

    func save(_ journal: UninstallJournal) throws {
        self.journal.withLock { $0 = journal }
    }

    func clear() throws {
        journal.withLock { $0 = nil }
    }
}

// MARK: - The app bundle

/// Moving the running app to the Trash, behind a protocol: nothing in the test suite ever
/// trashes a bundle (the test host is the app).
protocol AppBundleRemoving: Sendable {
    func moveToTrash(_ bundleURL: URL) async throws
}

/// `NSWorkspace.recycle` — the same thing the Finder does; the running process keeps running
/// from the Trash until it quits, which is why this is the last step.
struct WorkspaceAppBundleRemover: AppBundleRemoving {
    func moveToTrash(_ bundleURL: URL) async throws {
        try await withCheckedThrowingContinuation { (continuation: CheckedContinuation<Void, any Error>) in
            NSWorkspace.shared.recycle([bundleURL]) { _, error in
                if let error {
                    continuation.resume(throwing: UninstallError.bundleRemovalFailed("\((error as NSError).domain) \((error as NSError).code)"))
                    return
                }

                continuation.resume()
            }
        }
    }
}

/// Records what would have been trashed. Tests and the simulation only — never wired into `live`.
final class RecordingAppBundleRemover: AppBundleRemoving {
    private let urls = Mutex<[URL]>([])
    private let failure: UninstallError?

    init(failingWith failure: UninstallError? = nil) {
        self.failure = failure
    }

    var trashed: [URL] {
        urls.withLock { $0 }
    }

    func moveToTrash(_ bundleURL: URL) async throws {
        if let failure {
            throw failure
        }

        urls.withLock { $0.append(bundleURL) }
    }
}

// MARK: - Errors and report

enum UninstallError: Error, Equatable, Sendable {
    case unavailable(String)
    /// The agent is enabled but the Supervisor did not answer; nothing was changed.
    case supervisorUnreachable(String)
    case secretsFailed(String)
    case unregisterFailed(String)
    case removalFailed(String)
    case checkpointFailed(String)
    case journalFailed(String)
    case bundleRemovalFailed(String)

    /// Never a path, never a value: this string reaches the Settings window.
    var userFacingReason: String {
        switch self {
        case .unavailable(let reason): return reason
        case .supervisorUnreachable: return "The Supervisor did not answer. Start the services and try again; nothing has been removed yet."
        case .secretsFailed: return "The Keychain items could not be removed. Try again; nothing has been removed yet."
        case .unregisterFailed: return "The launch agent could not be unregistered. Try again."
        case .removalFailed(let name): return "\(name) could not be removed. Try again."
        case .checkpointFailed: return "The data-preserving marker could not be written. Try again."
        case .journalFailed: return "The uninstall progress file could not be written. Try again."
        case .bundleRemovalFailed: return "The app could not be moved to the Trash. Drag it there yourself; everything else has been removed."
        }
    }
}

struct UninstallReport: Equatable, Sendable {
    let dataChoice: UninstallDataChoice
    /// Steps this run performed, in order.
    let performed: [UninstallStep]
    /// Steps a previous, interrupted run had already finished.
    let resumedFrom: [UninstallStep]
    let secretsSweep: SecretsSweep
    /// `data/` when it was kept.
    let preservedData: URL?
    let appBundleTrashed: Bool
}

// MARK: - Dependencies

struct UninstallDependencies: Sendable {
    var paths: InstallPaths
    var productVersion: String
    var control: any SupervisorControlling
    var registrar: any LaunchAgentRegistering
    var briefCache: BriefCache
    var checkpointStore: any InstallCheckpointStore
    var journalStore: any UninstallJournalStore
    var appBundleRemover: any AppBundleRemoving
    /// The running app's bundle, trashed last. nil leaves it in place (a build product run from
    /// a build directory is not an installed app).
    var appBundleURL: URL?
    var now: @Sendable () -> Date
    /// Called after each step has been journaled. Tests throw here to simulate a crash.
    var afterStep: (@Sendable (UninstallStep) async throws -> Void)?

    static func live(bundle: Bundle = .main, control: any SupervisorControlling) -> UninstallDependencies {
        let bundleIdentifier = bundle.bundleIdentifier ?? SupervisorIdentity.defaultBundlePrefix + ".app"
        let bundlePrefix = SupervisorIdentity.bundlePrefix(fromAppIdentifier: bundleIdentifier)
        let paths = InstallPaths.default(bundleIdentifier: bundleIdentifier)
        let version = bundle.object(forInfoDictionaryKey: "CFBundleShortVersionString") as? String ?? "0.0.0"

        return UninstallDependencies(
            paths: paths,
            productVersion: version,
            control: control,
            registrar: SMAppServiceRegistrar(plistName: InstallDependencies.supervisorPlistName(bundlePrefix: bundlePrefix)),
            briefCache: BriefCache(paths: paths),
            checkpointStore: FileInstallCheckpointStore(url: paths.checkpointURL),
            journalStore: FileUninstallJournalStore(url: paths.uninstallJournalURL),
            appBundleRemover: WorkspaceAppBundleRemover(),
            appBundleURL: bundle.bundleURL.pathExtension == "app" ? bundle.bundleURL : nil,
            now: { Date() },
            afterStep: nil
        )
    }
}

// MARK: - The orchestrator

protocol Uninstalling: Sendable {
    func uninstall(choice: UninstallDataChoice) async throws -> UninstallReport
}

/// Fails closed: a build without a control channel cannot sweep the Keychain, so it must not
/// pretend to uninstall.
struct UnavailableUninstaller: Uninstalling {
    func uninstall(choice: UninstallDataChoice) async throws -> UninstallReport {
        throw UninstallError.unavailable("Uninstall is not available in this build.")
    }
}

struct Uninstaller: Uninstalling {
    let dependencies: UninstallDependencies

    init(dependencies: UninstallDependencies) {
        self.dependencies = dependencies
    }

    func uninstall(choice: UninstallDataChoice) async throws -> UninstallReport {
        var journal = startOrResumeJournal(choice: choice)
        let resumedFrom = journal.completed
        var performed: [UninstallStep] = []

        if journal.completed.isEmpty {
            try persist(journal)
        }

        for step in UninstallStep.allCases where !journal.isDone(step) {
            try await perform(step, choice: choice, journal: &journal)

            journal.completed.append(step)
            journal.updatedAt = dependencies.now()
            performed.append(step)

            // After the delete path's step 6 the install directory is gone; writing the journal
            // again would leave a file behind.
            if choice == .keep || Self.index(of: step) < Self.index(of: .installDirectoryFinalized) {
                try persist(journal)
            }

            try await dependencies.afterStep?(step)
        }

        try? dependencies.journalStore.clear()

        return UninstallReport(
            dataChoice: choice,
            performed: performed,
            resumedFrom: resumedFrom,
            secretsSweep: journal.secretsSweep ?? .skipped(reason: "no sweep was recorded"),
            preservedData: choice.preservesData && FileManager.default.fileExists(atPath: dependencies.paths.dataRoot.path) ? dependencies.paths.dataRoot : nil,
            appBundleTrashed: performed.contains(.appBundleTrashed) && dependencies.appBundleURL != nil
        )
    }

    // MARK: Steps

    private func perform(_ step: UninstallStep, choice: UninstallDataChoice, journal: inout UninstallJournal) async throws {
        switch step {
        case .servicesStopped:
            try await stopServices()
        case .secretsRemoved:
            journal.secretsSweep = try await removeSecrets()
        case .agentUnregistered:
            try await unregisterAgent()
        case .runtimeRemoved:
            try removeRuntime(choice: choice)
        case .briefCacheCleared:
            try clearBriefCache()
        case .installDirectoryFinalized:
            try finalizeInstallDirectory(choice: choice, journal: journal)
        case .appBundleTrashed:
            try await trashAppBundle()
        }
    }

    /// Steps 1 and 2 need a Supervisor to talk to. An agent that is not `enabled` has no running
    /// Supervisor (never registered, waiting for Login Items approval, plist missing), so there
    /// is nothing to stop and nothing that can reach the access group.
    private var supervisorIsRegistered: Bool {
        dependencies.registrar.status() == .enabled
    }

    private func stopServices() async throws {
        guard supervisorIsRegistered else {
            return
        }

        do {
            try await dependencies.control.stopAll()
        } catch let error as SupervisorControlError {
            throw UninstallError.supervisorUnreachable(Self.code(error))
        }
    }

    private func removeSecrets() async throws -> SecretsSweep {
        let status = dependencies.registrar.status()

        guard status == .enabled else {
            return .skipped(reason: "launch agent \(status.rawValue)")
        }

        do {
            return .removed(count: try await dependencies.control.removeAllSecrets().count)
        } catch let error as SupervisorControlError {
            switch error {
            case .unreachable:
                throw UninstallError.supervisorUnreachable(Self.code(error))
            default:
                throw UninstallError.secretsFailed(Self.code(error))
            }
        }
    }

    private func unregisterAgent() async throws {
        guard dependencies.registrar.status() != .notRegistered else {
            return
        }

        do {
            try await dependencies.registrar.unregister()
        } catch {
            throw UninstallError.unregisterFailed("the launch agent could not be unregistered")
        }
    }

    private func removeRuntime(choice: UninstallDataChoice) throws {
        do {
            _ = try RuntimeUninstaller().remove(preservingData: choice.preservesData, includingInstallDirectory: false, in: dependencies.paths)
        } catch let error as RuntimeUninstallError {
            switch error {
            case .removalFailed(let path):
                throw UninstallError.removalFailed(path)
            }
        }
    }

    private func clearBriefCache() throws {
        do {
            try dependencies.briefCache.clear()
        } catch {
            throw UninstallError.removalFailed("brief cache")
        }
    }

    private func finalizeInstallDirectory(choice: UninstallDataChoice, journal: UninstallJournal) throws {
        let fileManager = FileManager.default
        let paths = dependencies.paths

        if choice.preservesData {
            let marker = InstallCheckpoint.preservedData(ownerCreated: journal.ownerCreated, productVersion: journal.productVersion, at: dependencies.now())

            do {
                try dependencies.checkpointStore.save(marker)
            } catch {
                throw UninstallError.checkpointFailed("checkpoint")
            }

            return
        }

        for directory in [paths.installDirectory, paths.applicationSupport] where fileManager.fileExists(atPath: directory.path) {
            if directory == paths.applicationSupport, (try? fileManager.contentsOfDirectory(atPath: directory.path))?.isEmpty == false {
                // Something this app did not create is in there; it is not ours to delete.
                continue
            }

            do {
                try RuntimeInstaller.makeWritable(directory)
                try fileManager.removeItem(at: directory)
            } catch {
                throw UninstallError.removalFailed(directory.lastPathComponent)
            }
        }
    }

    private func trashAppBundle() async throws {
        guard let bundleURL = dependencies.appBundleURL else {
            return
        }

        try await dependencies.appBundleRemover.moveToTrash(bundleURL)
    }

    // MARK: Journal

    private func startOrResumeJournal(choice: UninstallDataChoice) -> UninstallJournal {
        if var journal = (try? dependencies.journalStore.load()) ?? nil {
            if journal.dataChoice != choice {
                // The person changed their mind between runs. Data is only touched in steps 4
                // and 6, so those two run again under the new choice; nothing else is repeated.
                journal.dataChoice = choice
                journal.completed.removeAll { $0 == .runtimeRemoved || $0 == .installDirectoryFinalized }
            }

            return journal
        }

        let checkpoint = (try? dependencies.checkpointStore.load()) ?? nil
        let ownerCreated = checkpoint?.ownerCreated ?? FileManager.default.fileExists(atPath: dependencies.paths.databaseURL.path)

        return UninstallJournal(
            startedAt: dependencies.now(),
            dataChoice: choice,
            ownerCreated: ownerCreated,
            productVersion: dependencies.productVersion
        )
    }

    private func persist(_ journal: UninstallJournal) throws {
        do {
            try dependencies.journalStore.save(journal)
        } catch {
            throw UninstallError.journalFailed("journal")
        }
    }

    private static func index(of step: UninstallStep) -> Int {
        UninstallStep.allCases.firstIndex(of: step) ?? 0
    }

    private static func code(_ error: SupervisorControlError) -> String {
        SupervisorControlWire.code(for: error)
    }
}
