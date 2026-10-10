// On-disk layout the installer works with (ADR-001 D4), rooted at the user-data directory
// `~/Library/Application Support/<bundle id>/`. Every path is derived from one root so tests
// and the DEBUG simulation can point the whole installer at a temporary directory.
//
//   Application Support/<bundle id>/
//     runtime/<version>/   immutable (chmod -R a-w), verified on every boot
//     runtime/current      symlink, flipped atomically
//     runtime/previous     symlink, kept for rollback
//     data/                mutable: database/, storage/, backups/
//     install/             checkpoint.json
//   Logs/<bundle id>/      runtime + supervisor + install logs; data/storage/logs points here
//   Caches/<bundle id>/    may be lost at any time
//
// The full contract, including what the Supervisor passes to PHP, is
// docs/architecture/runtime-layout.md.

import Foundation

struct InstallPaths: Equatable, Sendable {
    /// `~/Library/Application Support/<bundle id>/` — app-owned runtime plus user data.
    let applicationSupport: URL
    /// `~/Library/Logs/<bundle id>/` — install, supervisor and runtime logs; never backed up.
    let logs: URL
    /// `~/Library/Caches/<bundle id>/` — caches that may be lost at any time; never backed up.
    let caches: URL

    static func `default`(bundleIdentifier: String, fileManager: FileManager = .default) -> InstallPaths {
        let library = fileManager.urls(for: .libraryDirectory, in: .userDomainMask).first
            ?? fileManager.homeDirectoryForCurrentUser.appending(path: "Library")

        return InstallPaths(
            applicationSupport: library.appending(path: "Application Support").appending(path: bundleIdentifier),
            logs: library.appending(path: "Logs").appending(path: bundleIdentifier),
            caches: library.appending(path: "Caches").appending(path: bundleIdentifier)
        )
    }

    /// Everything under one root; used by tests and the DEBUG simulation.
    static func rooted(at root: URL) -> InstallPaths {
        InstallPaths(
            applicationSupport: root.appending(path: "Application Support"),
            logs: root.appending(path: "Logs"),
            caches: root.appending(path: "Caches")
        )
    }

    // ADR-001 D4 runtime side (immutable).
    var runtimeRoot: URL { applicationSupport.appending(path: "runtime") }
    var currentRuntimeLink: URL { runtimeRoot.appending(path: "current") }
    var previousRuntimeLink: URL { runtimeRoot.appending(path: "previous") }

    func runtimeDirectory(version: String) -> URL {
        runtimeRoot.appending(path: version)
    }

    func stagingDirectory(version: String) -> URL {
        runtimeRoot.appending(path: ".staging-\(version)")
    }

    /// The version `current` points at, if the link exists.
    func currentRuntimeVersion(fileManager: FileManager = .default) -> String? {
        try? fileManager.destinationOfSymbolicLink(atPath: currentRuntimeLink.path)
    }

    /// The version `previous` points at, if the link exists.
    func previousRuntimeVersion(fileManager: FileManager = .default) -> String? {
        try? fileManager.destinationOfSymbolicLink(atPath: previousRuntimeLink.path)
    }

    // ADR-001 D4 data side (mutable, backed up — except the live database, see
    // runtime-layout.md §4 and ADR-002 D5).
    var dataRoot: URL { applicationSupport.appending(path: "data") }
    var databaseDirectory: URL { dataRoot.appending(path: "database") }
    var databaseURL: URL { databaseDirectory.appending(path: "executive.sqlite") }
    var storageDirectory: URL { dataRoot.appending(path: "storage") }
    var storageAppDirectory: URL { storageDirectory.appending(path: "app") }
    var storageFrameworkDirectory: URL { storageDirectory.appending(path: "framework") }
    /// `data/storage/logs` is a symlink to `logs`, so Laravel's log channel lands in ~/Library/Logs.
    var storageLogsLink: URL { storageDirectory.appending(path: "logs") }
    var backupsDirectory: URL { dataRoot.appending(path: "backups") }

    /// Every directory the installer creates under `data/`, relative to `dataRoot`.
    static let dataSubdirectories = [
        "database",
        "storage/app/private",
        "storage/app/public",
        "storage/framework/cache/data",
        "storage/framework/sessions",
        "storage/framework/views",
        "backups",
    ]

    /// Directories excluded from Time Machine (`tmutil addexclusion`, ADR-002 D5 / A21): the live
    /// SQLite file with its WAL, and the framework scratch space. `data/backups` and
    /// `data/storage/app` stay inside Time Machine on purpose — the verified snapshots are what
    /// gets backed up.
    var backupExcludedDirectories: [URL] {
        [databaseDirectory, storageFrameworkDirectory]
    }

    // Installer bookkeeping.
    var installDirectory: URL { applicationSupport.appending(path: "install") }
    var checkpointURL: URL { installDirectory.appending(path: "checkpoint.json") }

    func eventLogURL(runID: UUID) -> URL {
        logs.appending(path: "install-events-\(runID.uuidString.lowercased()).ndjson")
    }
}
