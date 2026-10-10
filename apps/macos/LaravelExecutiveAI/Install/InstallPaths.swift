// On-disk layout the installer works with (ADR-001 D4), rooted at the user-data directory
// `~/Library/Application Support/<bundle id>/`. Every path is derived from one root so tests
// and the DEBUG simulation can point the whole installer at a temporary directory.

import Foundation

struct InstallPaths: Equatable, Sendable {
    /// `~/Library/Application Support/<bundle id>/` — app-owned runtime plus user data.
    let applicationSupport: URL
    /// `~/Library/Logs/<bundle id>/` — install event logs; never backed up.
    let logs: URL

    static func `default`(bundleIdentifier: String, fileManager: FileManager = .default) -> InstallPaths {
        let library = fileManager.urls(for: .libraryDirectory, in: .userDomainMask).first
            ?? fileManager.homeDirectoryForCurrentUser.appending(path: "Library")

        return InstallPaths(
            applicationSupport: library.appending(path: "Application Support").appending(path: bundleIdentifier),
            logs: library.appending(path: "Logs").appending(path: bundleIdentifier)
        )
    }

    /// Everything under one root; used by tests and the DEBUG simulation.
    static func rooted(at root: URL) -> InstallPaths {
        InstallPaths(
            applicationSupport: root.appending(path: "Application Support"),
            logs: root.appending(path: "Logs")
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

    // ADR-001 D4 data side (mutable, backed up).
    var dataRoot: URL { applicationSupport.appending(path: "data") }
    var databaseDirectory: URL { dataRoot.appending(path: "database") }
    var databaseURL: URL { databaseDirectory.appending(path: "executive.sqlite") }
    var storageDirectory: URL { dataRoot.appending(path: "storage") }
    var backupsDirectory: URL { dataRoot.appending(path: "backups") }

    // Installer bookkeeping.
    var installDirectory: URL { applicationSupport.appending(path: "install") }
    var checkpointURL: URL { installDirectory.appending(path: "checkpoint.json") }

    func eventLogURL(runID: UUID) -> URL {
        logs.appending(path: "install-events-\(runID.uuidString.lowercased()).ndjson")
    }
}
