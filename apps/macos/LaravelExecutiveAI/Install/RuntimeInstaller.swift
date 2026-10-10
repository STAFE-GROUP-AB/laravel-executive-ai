// INSTALL_CORE hand-off (S1-05 defines; S1-06 owns the full runtime install).
//
// What the state machine needs from the runtime installer: real checkpoints it can show (§12.1
// "progress must reflect real checkpoints"), cancellation between files, and a rollback that
// leaves no partial runtime behind. `StagingRuntimeInstaller` is the minimal honest version of
// that: it copies a *verified* package into `runtime/.staging-<version>/`, re-hashes what it
// copied, then activates by renaming the staging directory to `runtime/<version>/` and flipping
// the `current`/`previous` symlinks (ADR-001 D4). S1-06 extends it (Caddyfile, php.ini,
// `runtime.json`, read-only permissions, Time Machine exclusion, data-preserving upgrade).

import Foundation

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
    case activationFailed(String)
    case rollbackFailed(String)

    var userFacingReason: String {
        switch self {
        case .unavailable(let reason): return reason
        case .copyFailed(let path): return "Copying \(path) failed."
        case .verificationAfterCopyFailed(let path): return "\(path) did not verify after copying."
        case .activationFailed(let reason): return "Activating the runtime failed (\(reason))."
        case .rollbackFailed(let reason): return "Removing the partial runtime failed (\(reason))."
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
}

struct StagingRuntimeInstaller: RuntimeInstalling {
    /// Called before each file is copied, with its index. Tests use it to block or to inject a
    /// failure at a precise point; the product passes nil.
    let beforeEachFile: (@Sendable (Int) async throws -> Void)?

    init(beforeEachFile: (@Sendable (Int) async throws -> Void)? = nil) {
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

            guard let digest = try? PackageVerifier.sha256Hex(of: copied), digest == entry.sha256 else {
                throw RuntimeInstallError.verificationAfterCopyFailed(path: entry.path)
            }
        }

        try Task.checkCancellation()
        progress(.activatingRuntime)
        let installed = try activate(staging: staging, version: version, paths: paths)

        progress(.preparingDataDirectory)

        for directory in [paths.databaseDirectory, paths.storageDirectory, paths.backupsDirectory] {
            try fileManager.createDirectory(at: directory, withIntermediateDirectories: true)
        }

        progress(.completed)

        return installed
    }

    func rollback(_ handle: RuntimeInstallHandle, in paths: InstallPaths) async throws {
        let fileManager = FileManager.default
        let staging = paths.runtimeRoot.appending(path: handle.stagingDirectoryName)

        if fileManager.fileExists(atPath: staging.path) {
            do {
                try fileManager.removeItem(at: staging)
            } catch {
                throw RuntimeInstallError.rollbackFailed("staging directory could not be removed")
            }
        }

        // A version directory that exists but is not what `current` points at was never
        // activated by this run (activation flips the link last); remove it too.
        let versionDirectory = paths.runtimeDirectory(version: handle.version)
        let currentTarget = try? fileManager.destinationOfSymbolicLink(atPath: paths.currentRuntimeLink.path)

        if fileManager.fileExists(atPath: versionDirectory.path), currentTarget != handle.version {
            do {
                try fileManager.removeItem(at: versionDirectory)
            } catch {
                throw RuntimeInstallError.rollbackFailed("version directory could not be removed")
            }
        }
    }

    private func activate(staging: URL, version: String, paths: InstallPaths) throws -> InstalledRuntime {
        let fileManager = FileManager.default
        let destination = paths.runtimeDirectory(version: version)

        if fileManager.fileExists(atPath: destination.path) {
            // Same version already present (reinstall). Replace it; `current` still points at the
            // old directory name, which is this one, so the flip below is a no-op for the link.
            do {
                try fileManager.removeItem(at: destination)
            } catch {
                throw RuntimeInstallError.activationFailed("the existing runtime directory could not be replaced")
            }
        }

        do {
            try fileManager.moveItem(at: staging, to: destination)
        } catch {
            throw RuntimeInstallError.activationFailed("the staged runtime could not be moved into place")
        }

        let previousTarget = try? fileManager.destinationOfSymbolicLink(atPath: paths.currentRuntimeLink.path)

        if let previousTarget, previousTarget != version {
            try? fileManager.removeItem(at: paths.previousRuntimeLink)
            try? fileManager.createSymbolicLink(atPath: paths.previousRuntimeLink.path, withDestinationPath: previousTarget)
        }

        // Atomic flip: create the new link under a temporary name, then rename over `current`.
        let temporaryLink = paths.runtimeRoot.appending(path: ".current-\(UUID().uuidString)")

        do {
            try fileManager.createSymbolicLink(atPath: temporaryLink.path, withDestinationPath: version)

            if rename(temporaryLink.path, paths.currentRuntimeLink.path) != 0 {
                try? fileManager.removeItem(at: temporaryLink)
                throw RuntimeInstallError.activationFailed("the current link could not be switched")
            }
        } catch let error as RuntimeInstallError {
            throw error
        } catch {
            throw RuntimeInstallError.activationFailed("the current link could not be created")
        }

        return InstalledRuntime(version: version, directory: destination)
    }
}

struct UnavailableRuntimeInstaller: RuntimeInstalling {
    func install(
        _ package: VerifiedPackage,
        into paths: InstallPaths,
        started: @escaping @Sendable (RuntimeInstallHandle) -> Void,
        progress: @escaping @Sendable (InstallCoreCheckpoint) -> Void
    ) async throws -> InstalledRuntime {
        throw RuntimeInstallError.unavailable("The runtime installer arrives with S1-06.")
    }

    func rollback(_ handle: RuntimeInstallHandle, in paths: InstallPaths) async throws {}
}
