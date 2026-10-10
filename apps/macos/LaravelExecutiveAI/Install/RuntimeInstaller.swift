// INSTALL_CORE: the app-owned, immutable, versioned runtime and the separate user data
// directory (S1-06, ADR-001 D4; S1-05 defined the hand-off).
//
// What the state machine needs from the runtime installer: real checkpoints it can show (§12.1
// "progress must reflect real checkpoints"), cancellation between files, and a rollback that
// leaves no partial runtime behind. `RuntimeInstaller` copies a *verified* package into
// `runtime/.staging-<version>/`, re-hashes what it copied, renames the staging directory to
// `runtime/<version>/`, makes that tree read-only in place, then flips the `current`/`previous`
// symlinks atomically (so nothing writable is ever visible as the active runtime),
// garbage-collects versions older than `previous`, and prepares `data/`, the Logs and Caches
// directories and the Time Machine exclusion.
//
// Nothing in the runtime directory is generated per machine. The Caddyfile and php.ini read the
// port and the paths from the process environment the Supervisor passes (runtime-layout.md), so
// `runtime/<version>/` is byte-for-byte what `manifest.json` describes and `PackageVerifier`
// accepts it unchanged on every boot (ADR-001 D3).

import Foundation
import Synchronization

/// A checkpoint the install screen renders. Every value is something that actually happened.
enum InstallCoreCheckpoint: Equatable, Sendable {
    case preparingDirectories
    case copyingRuntime(filesDone: Int, filesTotal: Int, bytesDone: Int64, bytesTotal: Int64)
    case verifyingInstalledFiles
    case activatingRuntime
    case preparingDataDirectory
    case completed

    var label: String {
        switch self {
        case .preparingDirectories: return "Preparing folders"
        case .copyingRuntime(let done, let total, _, _): return "Copying runtime (\(done) of \(total) files)"
        case .verifyingInstalledFiles: return "Verifying installed files"
        case .activatingRuntime: return "Activating runtime"
        case .preparingDataDirectory: return "Preparing data folder"
        case .completed: return "Runtime installed"
        }
    }

    /// 0…1 derived from bytes copied, with the fixed steps spread around them.
    var fraction: Double {
        switch self {
        case .preparingDirectories: return 0.02
        case .copyingRuntime(_, _, let bytesDone, let bytesTotal):
            let copied = bytesTotal > 0 ? Double(bytesDone) / Double(bytesTotal) : 1
            return 0.05 + 0.80 * min(max(copied, 0), 1)
        case .verifyingInstalledFiles: return 0.88
        case .activatingRuntime: return 0.94
        case .preparingDataDirectory: return 0.97
        case .completed: return 1
        }
    }
}

/// Bookkeeping the installer hands back as it goes, persisted in the checkpoint.
struct RuntimeInstallHandle: Equatable, Sendable {
    let version: String
    let stagingDirectoryName: String
}

enum RuntimeInstallError: Error, Equatable, Sendable {
    case unavailable(String)
    case copyFailed(path: String)
    case verificationAfterCopyFailed(path: String)
    case permissionsFailed(String)
    case activationFailed(String)
    case dataDirectoryFailed(String)
    case backupExclusionFailed(String)
    case rollbackFailed(String)
    case noPreviousRuntime

    var userFacingReason: String {
        switch self {
        case .unavailable(let reason): return reason
        case .copyFailed(let path): return "Copying \(path) failed."
        case .verificationAfterCopyFailed(let path): return "\(path) did not verify after copying."
        case .permissionsFailed(let reason): return "Making the runtime read-only failed (\(reason))."
        case .activationFailed(let reason): return "Activating the runtime failed (\(reason))."
        case .dataDirectoryFailed(let reason): return "Preparing the data folder failed (\(reason))."
        case .backupExclusionFailed(let reason): return "Excluding the live database from Time Machine failed (\(reason))."
        case .rollbackFailed(let reason): return "Removing the partial runtime failed (\(reason))."
        case .noPreviousRuntime: return "There is no previous runtime to roll back to."
        }
    }
}

protocol RuntimeInstalling: Sendable {
    /// Installs a verified package. `started` is called once the staging directory exists so the
    /// caller can persist a rollback handle; `progress` is called at every checkpoint. Must check
    /// for cancellation between files and throw `CancellationError`.
    func install(
        _ package: VerifiedPackage,
        into paths: InstallPaths,
        started: @escaping @Sendable (RuntimeInstallHandle) -> Void,
        progress: @escaping @Sendable (InstallCoreCheckpoint) -> Void
    ) async throws -> InstalledRuntime

    /// Removes everything a run with this handle left behind that was not activated. Idempotent.
    func rollback(_ handle: RuntimeInstallHandle, in paths: InstallPaths) async throws

    /// Flips `current` back to `previous` (ADR-001 D4, §9 binary rollback). `data/` is untouched;
    /// an incompatible schema change is not rolled back automatically (§9).
    func rollbackToPrevious(in paths: InstallPaths) async throws -> InstalledRuntime
}

// MARK: - Time Machine exclusion (ADR-002 D5, OPEN-QUESTIONS A21)

protocol BackupExcluding: Sendable {
    /// Marks `url` so Time Machine never copies it. The item-level (sticky) exclusion follows the
    /// item if it is moved and needs no privileges.
    func exclude(_ url: URL) throws
}

/// `/usr/bin/tmutil addexclusion <path>` as the current user. Chosen over
/// `URLResourceKey.isExcludedFromBackupKey` for the data directory because the resource key
/// resets after common file operations (ADR-003 E26) and the live database is exactly the file
/// that must never be copied (threat model T-43).
struct TimeMachineBackupExclusion: BackupExcluding {
    func exclude(_ url: URL) throws {
        let process = Process()
        process.executableURL = URL(fileURLWithPath: "/usr/bin/tmutil")
        process.arguments = ["addexclusion", url.path]
        process.standardInput = FileHandle.nullDevice
        process.standardOutput = FileHandle.nullDevice

        let stderr = Pipe()
        process.standardError = stderr

        do {
            try process.run()
        } catch {
            throw RuntimeInstallError.backupExclusionFailed("tmutil could not be started")
        }

        let errorText = String(decoding: stderr.fileHandleForReading.readDataToEndOfFile(), as: UTF8.self)
        process.waitUntilExit()

        guard process.terminationStatus == 0 else {
            let detail = errorText.trimmingCharacters(in: .whitespacesAndNewlines)
            throw RuntimeInstallError.backupExclusionFailed(detail.isEmpty ? "tmutil exited with status \(process.terminationStatus)" : detail)
        }
    }
}

/// Records what would have been excluded. Simulation and tests only — never wired into `live`.
final class RecordingBackupExclusion: BackupExcluding {
    private let excluded = Mutex<[URL]>([])
    private let failure: RuntimeInstallError?

    init(failingWith failure: RuntimeInstallError? = nil) {
        self.failure = failure
    }

    var excludedURLs: [URL] {
        excluded.withLock { $0 }
    }

    func exclude(_ url: URL) throws {
        if let failure {
            throw failure
        }

        excluded.withLock { $0.append(url) }
    }
}

// MARK: - The installer

struct RuntimeInstaller: RuntimeInstalling {
    /// Called before each file is copied, with its index. Tests use it to block or to inject a
    /// failure at a precise point; the product passes nil.
    let beforeEachFile: (@Sendable (Int) async throws -> Void)?
    let backupExclusion: any BackupExcluding

    init(backupExclusion: any BackupExcluding, beforeEachFile: (@Sendable (Int) async throws -> Void)? = nil) {
        self.backupExclusion = backupExclusion
        self.beforeEachFile = beforeEachFile
    }

    func install(
        _ package: VerifiedPackage,
        into paths: InstallPaths,
        started: @escaping @Sendable (RuntimeInstallHandle) -> Void,
        progress: @escaping @Sendable (InstallCoreCheckpoint) -> Void
    ) async throws -> InstalledRuntime {
        let fileManager = FileManager.default
        let version = package.version
        let staging = paths.stagingDirectory(version: version)

        progress(.preparingDirectories)
        try fileManager.createDirectory(at: paths.runtimeRoot, withIntermediateDirectories: true)

        if fileManager.fileExists(atPath: staging.path) {
            try Self.makeWritable(staging)
            try fileManager.removeItem(at: staging)
        }

        try fileManager.createDirectory(at: staging, withIntermediateDirectories: true)
        started(RuntimeInstallHandle(version: version, stagingDirectoryName: staging.lastPathComponent))

        let total = package.manifest.files.count
        var bytesDone: Int64 = 0
        progress(.copyingRuntime(filesDone: 0, filesTotal: total, bytesDone: 0, bytesTotal: package.totalBytes))

        for (index, entry) in package.manifest.files.enumerated() {
            try Task.checkCancellation()
            try await beforeEachFile?(index)
            try Task.checkCancellation()

            let source = package.directory.appending(path: entry.path)
            let destination = staging.appending(path: entry.path)

            do {
                try fileManager.createDirectory(at: destination.deletingLastPathComponent(), withIntermediateDirectories: true)
                try fileManager.copyItem(at: source, to: destination)
            } catch {
                throw RuntimeInstallError.copyFailed(path: entry.path)
            }

            bytesDone += entry.bytes
            progress(.copyingRuntime(filesDone: index + 1, filesTotal: total, bytesDone: bytesDone, bytesTotal: package.totalBytes))
        }

        for name in [PackageVerifier.manifestFileName, PackageVerifier.checksumFileName] {
            try Task.checkCancellation()

            do {
                try fileManager.copyItem(at: package.directory.appending(path: name), to: staging.appending(path: name))
            } catch {
                throw RuntimeInstallError.copyFailed(path: name)
            }
        }

        try Task.checkCancellation()
        progress(.verifyingInstalledFiles)

        for entry in package.manifest.files {
            try Task.checkCancellation()

            let copied = staging.appending(path: entry.path)

            guard let attributes = try? fileManager.attributesOfItem(atPath: copied.path),
                  let size = (attributes[.size] as? NSNumber)?.int64Value, size == entry.bytes,
                  let digest = try? PackageVerifier.sha256Hex(of: copied), digest == entry.sha256
            else {
                throw RuntimeInstallError.verificationAfterCopyFailed(path: entry.path)
            }
        }

        try Task.checkCancellation()
        progress(.activatingRuntime)
        let installed = try activate(staging: staging, version: version, paths: paths)
        collectGarbage(in: paths)

        progress(.preparingDataDirectory)
        try prepareDataDirectory(paths: paths)

        progress(.completed)

        return installed
    }

    func rollback(_ handle: RuntimeInstallHandle, in paths: InstallPaths) async throws {
        let fileManager = FileManager.default
        let staging = paths.runtimeRoot.appending(path: handle.stagingDirectoryName)

        if fileManager.fileExists(atPath: staging.path) {
            do {
                try Self.makeWritable(staging)
                try fileManager.removeItem(at: staging)
            } catch {
                throw RuntimeInstallError.rollbackFailed("staging directory could not be removed")
            }
        }

        // A version directory that exists but is not what `current` points at was never
        // activated by this run (activation flips the link last); remove it too.
        let versionDirectory = paths.runtimeDirectory(version: handle.version)

        if fileManager.fileExists(atPath: versionDirectory.path), paths.currentRuntimeVersion() != handle.version {
            do {
                try Self.makeWritable(versionDirectory)
                try fileManager.removeItem(at: versionDirectory)
            } catch {
                throw RuntimeInstallError.rollbackFailed("version directory could not be removed")
            }
        }
    }

    func rollbackToPrevious(in paths: InstallPaths) async throws -> InstalledRuntime {
        let fileManager = FileManager.default

        guard let previous = paths.previousRuntimeVersion(), previous != paths.currentRuntimeVersion() else {
            throw RuntimeInstallError.noPreviousRuntime
        }

        let directory = paths.runtimeDirectory(version: previous)
        var isDirectory: ObjCBool = false

        guard fileManager.fileExists(atPath: directory.path, isDirectory: &isDirectory), isDirectory.boolValue,
              fileManager.fileExists(atPath: directory.appending(path: PackageVerifier.manifestFileName).path)
        else {
            throw RuntimeInstallError.noPreviousRuntime
        }

        let current = paths.currentRuntimeVersion()
        try Self.flipLink(paths.currentRuntimeLink, to: previous, in: paths.runtimeRoot)

        if let current {
            try? Self.flipLink(paths.previousRuntimeLink, to: current, in: paths.runtimeRoot)
        } else {
            try? fileManager.removeItem(at: paths.previousRuntimeLink)
        }

        return InstalledRuntime(version: previous, directory: directory)
    }

    // MARK: Activation

    private func activate(staging: URL, version: String, paths: InstallPaths) throws -> InstalledRuntime {
        let fileManager = FileManager.default
        let destination = paths.runtimeDirectory(version: version)

        if fileManager.fileExists(atPath: destination.path) {
            // Same version already present (data-preserving reinstall). Replace it; `current`
            // still points at this directory name, so the flip below is a no-op for the link.
            do {
                try Self.makeWritable(destination)
                try fileManager.removeItem(at: destination)
            } catch {
                throw RuntimeInstallError.activationFailed("the existing runtime directory could not be replaced")
            }
        }

        // rename(2) directly: atomic within `runtime/`, and no Foundation fallback to
        // copy-and-delete. The tree is still writable at this point on purpose — macOS 15's
        // FileManager refuses to move a directory it cannot write (seen on the CI runner; macOS 26
        // allows it) — and becomes read-only in place right after, before `current` can point at it.
        guard rename(staging.path, destination.path) == 0 else {
            throw RuntimeInstallError.activationFailed("the staged runtime could not be moved into place")
        }

        // ADR-001 D4: chmod -R a-w. On failure the directory is left un-activated (`current` still
        // points elsewhere), which is exactly what `rollback(_:in:)` removes.
        do {
            try Self.makeReadOnly(destination)
        } catch {
            throw RuntimeInstallError.permissionsFailed("permissions could not be set")
        }

        let previousTarget = paths.currentRuntimeVersion()

        if let previousTarget, previousTarget != version {
            try? Self.flipLink(paths.previousRuntimeLink, to: previousTarget, in: paths.runtimeRoot)
        }

        try Self.flipLink(paths.currentRuntimeLink, to: version, in: paths.runtimeRoot)

        return InstalledRuntime(version: version, directory: destination)
    }

    /// Atomic flip: create the new link under a temporary name, then `rename(2)` over the old one.
    static func flipLink(_ link: URL, to target: String, in root: URL) throws {
        let fileManager = FileManager.default
        let temporaryLink = root.appending(path: ".\(link.lastPathComponent)-\(UUID().uuidString)")

        do {
            try fileManager.createSymbolicLink(atPath: temporaryLink.path, withDestinationPath: target)
        } catch {
            throw RuntimeInstallError.activationFailed("the \(link.lastPathComponent) link could not be created")
        }

        if rename(temporaryLink.path, link.path) != 0 {
            try? fileManager.removeItem(at: temporaryLink)
            throw RuntimeInstallError.activationFailed("the \(link.lastPathComponent) link could not be switched")
        }
    }

    /// Removes every version directory that is neither `current` nor `previous` (ADR-001 D4:
    /// "older versions are garbage-collected"), plus a `previous` link whose target is gone.
    /// Best effort: a leftover directory is a disk-space problem, not an install failure.
    func collectGarbage(in paths: InstallPaths) {
        let fileManager = FileManager.default
        let current = paths.currentRuntimeVersion()
        var previous = paths.previousRuntimeVersion()

        if let target = previous, !fileManager.fileExists(atPath: paths.runtimeDirectory(version: target).path) {
            try? fileManager.removeItem(at: paths.previousRuntimeLink)
            previous = nil
        }

        let keep = Set([current, previous].compactMap { $0 })

        guard let names = try? fileManager.contentsOfDirectory(atPath: paths.runtimeRoot.path) else {
            return
        }

        for name in names where RuntimeManifest.isVersion(name) && !keep.contains(name) {
            let directory = paths.runtimeDirectory(version: name)

            guard (try? fileManager.destinationOfSymbolicLink(atPath: directory.path)) == nil else {
                continue
            }

            try? Self.makeWritable(directory)
            try? fileManager.removeItem(at: directory)
        }
    }

    // MARK: Data directory

    private func prepareDataDirectory(paths: InstallPaths) throws {
        let fileManager = FileManager.default

        do {
            for relative in InstallPaths.dataSubdirectories {
                try fileManager.createDirectory(at: paths.dataRoot.appending(path: relative), withIntermediateDirectories: true)
            }

            try fileManager.createDirectory(at: paths.logs, withIntermediateDirectories: true)
            try fileManager.createDirectory(at: paths.caches, withIntermediateDirectories: true)
            try Self.linkStorageLogs(paths: paths)
        } catch {
            throw RuntimeInstallError.dataDirectoryFailed("a folder could not be created")
        }

        for directory in paths.backupExcludedDirectories {
            try backupExclusion.exclude(directory)
        }
    }

    /// `data/storage/logs -> ~/Library/Logs/<bundle id>`. An existing symlink is re-pointed, an
    /// empty real directory is replaced; a real directory with content is left alone, because it
    /// holds logs a person may want.
    static func linkStorageLogs(paths: InstallPaths) throws {
        let fileManager = FileManager.default
        let link = paths.storageLogsLink
        let target = paths.logs.path

        if let existing = try? fileManager.destinationOfSymbolicLink(atPath: link.path) {
            if existing != target {
                try fileManager.removeItem(at: link)
                try fileManager.createSymbolicLink(atPath: link.path, withDestinationPath: target)
            }

            return
        }

        var isDirectory: ObjCBool = false

        if fileManager.fileExists(atPath: link.path, isDirectory: &isDirectory) {
            guard isDirectory.boolValue, try fileManager.contentsOfDirectory(atPath: link.path).isEmpty else {
                return
            }

            try fileManager.removeItem(at: link)
        }

        try fileManager.createSymbolicLink(atPath: link.path, withDestinationPath: target)
    }

    // MARK: Permissions

    /// `chmod -R a-w`: clears every write bit on the tree rooted at `url`, files and directories.
    static func makeReadOnly(_ url: URL) throws {
        try setPermissions(under: url) { $0 & ~0o222 }
    }

    /// Restores the owner's write bit so the tree can be removed or replaced.
    static func makeWritable(_ url: URL) throws {
        try setPermissions(under: url) { $0 | 0o200 }
    }

    private static func setPermissions(under url: URL, _ transform: (Int) -> Int) throws {
        let fileManager = FileManager.default
        let keys: [URLResourceKey] = [.isSymbolicLinkKey]

        guard let enumerator = fileManager.enumerator(at: url, includingPropertiesForKeys: keys, options: []) else {
            return
        }

        // Directories first so the enumerator can still descend while permissions are changing;
        // a symlink's permissions are meaningless and chmod would follow it.
        var files: [URL] = []

        for case let child as URL in enumerator {
            let values = try child.resourceValues(forKeys: Set(keys))

            if values.isSymbolicLink == true {
                continue
            }

            files.append(child)
        }

        for item in files.reversed() + [url] {
            let attributes = try fileManager.attributesOfItem(atPath: item.path)

            guard let type = attributes[.type] as? FileAttributeType, type != .typeSymbolicLink,
                  let mode = (attributes[.posixPermissions] as? NSNumber)?.intValue
            else {
                continue
            }

            try fileManager.setAttributes([.posixPermissions: transform(mode)], ofItemAtPath: item.path)
        }
    }
}

// MARK: - Uninstall, file-system half (§12.2). `Uninstaller.swift` (S1-13) owns the order:
// services, Keychain and launchd first, then this, then the install directory and the bundle.

enum RuntimeUninstallError: Error, Equatable, Sendable {
    case removalFailed(path: String)

    var userFacingReason: String {
        switch self {
        case .removalFailed(let path): return "\(path) could not be removed."
        }
    }
}

struct RuntimeUninstaller: Sendable {
    struct Report: Equatable, Sendable {
        /// Directories that existed and were removed.
        let removed: [URL]
        /// `data/` when it was kept, nil when it was removed or never existed.
        let preservedData: URL?
    }

    /// Removes the runtime, the Supervisor's `run/` directory, the Caches and Logs directories,
    /// the install directory unless `includingInstallDirectory` is false (the S1-13 orchestrator
    /// keeps its journal there until the last step), and — unless `preservingData` — the data
    /// directory. Idempotent: a second call removes nothing and reports an empty list.
    func remove(preservingData: Bool, includingInstallDirectory: Bool = true, in paths: InstallPaths) throws -> Report {
        let fileManager = FileManager.default
        var removed: [URL] = []

        var targets = [paths.runtimeRoot, paths.runDirectory, paths.caches, paths.logs]

        if includingInstallDirectory {
            targets.insert(paths.installDirectory, at: 1)
        }

        if !preservingData {
            targets.append(paths.dataRoot)
        }

        for target in targets where Self.exists(target) {
            do {
                try RuntimeInstaller.makeWritable(target)
                try fileManager.removeItem(at: target)
                removed.append(target)
            } catch {
                throw RuntimeUninstallError.removalFailed(path: target.lastPathComponent)
            }
        }

        var preservedData: URL?

        if preservingData, Self.exists(paths.dataRoot) {
            preservedData = paths.dataRoot
            // The Logs directory is gone; leave a real, empty logs directory in its place so the
            // preserved tree is self-contained and a later reinstall can re-link it.
            if (try? fileManager.destinationOfSymbolicLink(atPath: paths.storageLogsLink.path)) != nil {
                try? fileManager.removeItem(at: paths.storageLogsLink)
                try? fileManager.createDirectory(at: paths.storageLogsLink, withIntermediateDirectories: true)
            }
        }

        if preservedData == nil, Self.exists(paths.applicationSupport),
           (try? fileManager.contentsOfDirectory(atPath: paths.applicationSupport.path))?.isEmpty == true {
            try? fileManager.removeItem(at: paths.applicationSupport)
        }

        return Report(removed: removed, preservedData: preservedData)
    }

    private static func exists(_ url: URL) -> Bool {
        FileManager.default.fileExists(atPath: url.path) || (try? FileManager.default.destinationOfSymbolicLink(atPath: url.path)) != nil
    }
}

// MARK: - Environment contract for the Supervisor (S1-07)

/// The non-secret process environment every PHP child gets (ADR-001 D4: "non-secret
/// configuration is passed as process environment by the Supervisor"). Documented in
/// docs/architecture/runtime-layout.md §3; the Laravel side reads `LEA_DATA_DIR` in
/// bootstrap/app.php. No key, token or password is ever in this dictionary (ADR-003 D7 row 12).
enum RuntimeEnvironment {
    static let dataDirectoryKey = "LEA_DATA_DIR"
    static let runtimeDirectoryKey = "LEA_RUNTIME_DIR"
    static let cacheDirectoryKey = "LEA_CACHE_DIR"
    static let logDirectoryKey = "LEA_LOG_DIR"
    static let httpPortKey = "LEA_HTTP_PORT"

    static func supervisorEnvironment(runtime: InstalledRuntime, paths: InstallPaths, port: Int) -> [String: String] {
        [
            runtimeDirectoryKey: runtime.directory.path,
            dataDirectoryKey: paths.dataRoot.path,
            cacheDirectoryKey: paths.caches.path,
            logDirectoryKey: paths.logs.path,
            httpPortKey: String(port),
            "PHPRC": runtime.directory.path,
            "APP_ENV": "production",
            "APP_DEBUG": "false",
            "APP_URL": "http://127.0.0.1:\(port)",
            "LEA_BACKEND_PROFILE": "local",
        ]
    }
}

struct UnavailableRuntimeInstaller: RuntimeInstalling {
    func install(
        _ package: VerifiedPackage,
        into paths: InstallPaths,
        started: @escaping @Sendable (RuntimeInstallHandle) -> Void,
        progress: @escaping @Sendable (InstallCoreCheckpoint) -> Void
    ) async throws -> InstalledRuntime {
        throw RuntimeInstallError.unavailable("The runtime installer is not available in this build.")
    }

    func rollback(_ handle: RuntimeInstallHandle, in paths: InstallPaths) async throws {}

    func rollbackToPrevious(in paths: InstallPaths) async throws -> InstalledRuntime {
        throw RuntimeInstallError.noPreviousRuntime
    }
}
