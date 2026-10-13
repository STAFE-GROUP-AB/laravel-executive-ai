// S1-06 acceptance (issue #34): the runtime lands in a versioned, app-owned directory that is
// not user-writable; user data lives in a separate directory; two versions coexist so rollback
// is physically possible; a reinstall or upgrade never touches data/; uninstall can remove the
// runtime while preserving data; the installed directory still passes PackageVerifier.

import Foundation
import Synchronization
import XCTest
@testable import LaravelExecutiveAI

final class RuntimeInstallerTests: XCTestCase {
    private var root: URL!
    private var paths: InstallPaths!
    private var exclusion: RecordingBackupExclusion!
    private var installer: RuntimeInstaller!

    override func setUpWithError() throws {
        root = FileManager.default.temporaryDirectory.appending(path: "lxai-runtime-\(UUID().uuidString)")
        try FileManager.default.createDirectory(at: root, withIntermediateDirectories: true)
        paths = InstallPaths.rooted(at: root)
        exclusion = RecordingBackupExclusion()
        installer = RuntimeInstaller(backupExclusion: exclusion)
    }

    override func tearDown() {
        try? RuntimeInstaller.makeWritable(root)
        try? FileManager.default.removeItem(at: root)
    }

    // MARK: Helpers

    private func package(version: String, marker: String = "") throws -> VerifiedPackage {
        let directory = try SimulatedRuntimePackage.write(under: root.appending(path: "bundle-\(version)"), version: version, files: [
            ("frankenphp", Data("#!/bin/sh\necho runtime \(version)\(marker)\n".utf8)),
            ("Caddyfile", Data("bind 127.0.0.1\nhttp://127.0.0.1:{$LEA_HTTP_PORT}\n".utf8)),
            ("php.ini", Data("opcache.jit=disable\n".utf8)),
            ("app/public/index.php", Data("<?php echo '\(version)';\n".utf8)),
            ("app/vendor/autoload.php", Data("<?php\n".utf8)),
        ])

        return try PackageVerifier(signatureChecker: AcceptAnySignatureChecker()).verify(runtimeDirectory: directory)
    }

    @discardableResult
    private func install(_ version: String, marker: String = "") async throws -> InstalledRuntime {
        try await installer.install(try package(version: version, marker: marker), into: paths, started: { _ in }, progress: { _ in })
    }

    private func link(_ url: URL) -> String? {
        try? FileManager.default.destinationOfSymbolicLink(atPath: url.path)
    }

    private func exists(_ url: URL) -> Bool {
        FileManager.default.fileExists(atPath: url.path)
    }

    private func writeDatabase(_ contents: String = "SQLite format 3\u{0}pretend") throws -> Data {
        try FileManager.default.createDirectory(at: paths.databaseDirectory, withIntermediateDirectories: true)
        let data = Data(contents.utf8)
        try data.write(to: paths.databaseURL)

        return data
    }

    // MARK: Layout and immutability

    func testInstallProducesTheAdr001Layout() async throws {
        var checkpoints: [InstallCoreCheckpoint] = []
        let collector = CheckpointCollector()
        let runtime = try await installer.install(try package(version: "0.1.0"), into: paths, started: { _ in }, progress: { collector.record($0) })
        checkpoints = collector.checkpoints

        XCTAssertEqual(runtime.version, "0.1.0")
        XCTAssertEqual(runtime.directory, paths.runtimeDirectory(version: "0.1.0"))

        for file in ["frankenphp", "Caddyfile", "php.ini", "app/public/index.php", "manifest.json", "SHA256SUMS"] {
            XCTAssertTrue(exists(runtime.directory.appending(path: file)), file)
        }

        XCTAssertEqual(link(paths.currentRuntimeLink), "0.1.0")
        XCTAssertNil(link(paths.previousRuntimeLink), "a first install has no previous")
        XCTAssertFalse(exists(paths.stagingDirectory(version: "0.1.0")))

        for relative in InstallPaths.dataSubdirectories {
            XCTAssertTrue(exists(paths.dataRoot.appending(path: relative)), relative)
        }

        XCTAssertTrue(exists(paths.logs))
        XCTAssertTrue(exists(paths.caches))
        XCTAssertEqual(link(paths.storageLogsLink), paths.logs.path, "data/storage/logs points at ~/Library/Logs/<bundle id>")
        XCTAssertFalse(exists(paths.applicationSupport.appending(path: ".env")))
        XCTAssertFalse(exists(runtime.directory.appending(path: ".env")))

        XCTAssertEqual(checkpoints.first, .preparingDirectories)
        XCTAssertEqual(checkpoints.last, .completed)
        XCTAssertTrue(checkpoints.contains(.verifyingInstalledFiles))
        XCTAssertTrue(checkpoints.contains(.activatingRuntime))
        XCTAssertTrue(checkpoints.contains(.preparingDataDirectory))
    }

    func testInstalledRuntimeIsNotUserWritable() async throws {
        let runtime = try await install("0.1.0")
        let fileManager = FileManager.default

        let enumerator = try XCTUnwrap(fileManager.enumerator(at: runtime.directory, includingPropertiesForKeys: [.isSymbolicLinkKey]))
        var inspected = 0

        while let url = enumerator.nextObject() as? URL {
            let mode = try XCTUnwrap((fileManager.attributesOfItem(atPath: url.path)[.posixPermissions] as? NSNumber)?.intValue)
            XCTAssertEqual(mode & 0o222, 0, "\(url.lastPathComponent) has a write bit: \(String(mode, radix: 8))")
            XCTAssertFalse(fileManager.isWritableFile(atPath: url.path), url.lastPathComponent)
            inspected += 1
        }

        XCTAssertGreaterThanOrEqual(inspected, 7)
        XCTAssertFalse(fileManager.isWritableFile(atPath: runtime.directory.path))

        XCTAssertThrowsError(try Data("tamper".utf8).write(to: runtime.directory.appending(path: "Caddyfile")), "files are read-only")
        XCTAssertThrowsError(try Data("new".utf8).write(to: runtime.directory.appending(path: "dropped.php")), "the directory refuses new files")
        XCTAssertThrowsError(try fileManager.removeItem(at: runtime.directory.appending(path: "php.ini")), "the directory refuses deletions")

        XCTAssertTrue(fileManager.isWritableFile(atPath: paths.dataRoot.path), "data/ stays writable")
        XCTAssertTrue(fileManager.isWritableFile(atPath: paths.databaseDirectory.path))
    }

    func testInstalledDirectoryStillPassesPackageVerification() async throws {
        let runtime = try await install("0.1.0")
        let verifier = PackageVerifier(signatureChecker: AcceptAnySignatureChecker())

        let verified = try verifier.verify(runtimeDirectory: runtime.directory)
        XCTAssertEqual(verified.version, "0.1.0")

        // Through the `current` link, which is how the Supervisor resolves it on every boot.
        let viaLink = try verifier.verify(runtimeDirectory: paths.currentRuntimeLink)
        XCTAssertEqual(viaLink.manifest, verified.manifest)
    }

    func testTimeMachineExclusionCoversTheLiveDatabaseButNotTheBackups() async throws {
        try await install("0.1.0")

        XCTAssertEqual(Set(exclusion.excludedURLs.map(\.path)), Set([paths.databaseDirectory.path, paths.storageFrameworkDirectory.path]))
        XCTAssertFalse(exclusion.excludedURLs.contains(paths.backupsDirectory))
        XCTAssertFalse(exclusion.excludedURLs.contains(paths.dataRoot), "data/backups must remain inside Time Machine (ADR-002 D5)")
    }

    func testFailingTimeMachineExclusionFailsTheInstall() async throws {
        installer = RuntimeInstaller(backupExclusion: RecordingBackupExclusion(failingWith: .backupExclusionFailed("simulated")))

        do {
            try await install("0.1.0")
            XCTFail("expected the install to fail closed")
        } catch let error as RuntimeInstallError {
            XCTAssertEqual(error, .backupExclusionFailed("simulated"))
        }
    }

    // MARK: Coexistence, rollback, garbage collection

    func testTwoVersionsCoexistAndDataIsUntouchedByAnUpgrade() async throws {
        let before = try writeDatabase()
        try await install("0.1.0")
        let afterFirst = try Data(contentsOf: paths.databaseURL)
        XCTAssertEqual(afterFirst, before, "a fresh install never rewrites an existing database")

        try Data("session".utf8).write(to: paths.storageFrameworkDirectory.appending(path: "sessions/abc"))
        try Data("snapshot".utf8).write(to: paths.backupsDirectory.appending(path: "app-1.sqlite"))

        let upgraded = try await install("0.2.0")

        XCTAssertEqual(upgraded.version, "0.2.0")
        XCTAssertTrue(exists(paths.runtimeDirectory(version: "0.1.0")), "the old version stays for rollback")
        XCTAssertTrue(exists(paths.runtimeDirectory(version: "0.2.0")))
        XCTAssertEqual(link(paths.currentRuntimeLink), "0.2.0")
        XCTAssertEqual(link(paths.previousRuntimeLink), "0.1.0")

        XCTAssertEqual(try Data(contentsOf: paths.databaseURL), before, "the SQLite file is byte-identical after the upgrade")
        XCTAssertEqual(try String(contentsOf: paths.storageFrameworkDirectory.appending(path: "sessions/abc"), encoding: .utf8), "session")
        XCTAssertEqual(try String(contentsOf: paths.backupsDirectory.appending(path: "app-1.sqlite"), encoding: .utf8), "snapshot")

        XCTAssertEqual(try String(contentsOf: paths.runtimeDirectory(version: "0.1.0").appending(path: "app/public/index.php"), encoding: .utf8), "<?php echo '0.1.0';\n")
        XCTAssertEqual(try String(contentsOf: paths.runtimeDirectory(version: "0.2.0").appending(path: "app/public/index.php"), encoding: .utf8), "<?php echo '0.2.0';\n")
    }

    func testRollbackToPreviousFlipsTheLinksAndLeavesDataAlone() async throws {
        let before = try writeDatabase()
        try await install("0.1.0")
        try await install("0.2.0")

        let rolledBack = try await installer.rollbackToPrevious(in: paths)

        XCTAssertEqual(rolledBack.version, "0.1.0")
        XCTAssertEqual(rolledBack.directory, paths.runtimeDirectory(version: "0.1.0"))
        XCTAssertEqual(link(paths.currentRuntimeLink), "0.1.0")
        XCTAssertEqual(link(paths.previousRuntimeLink), "0.2.0", "the version rolled back from is kept, so a roll-forward is possible")
        XCTAssertTrue(exists(paths.runtimeDirectory(version: "0.2.0")))
        XCTAssertEqual(try Data(contentsOf: paths.databaseURL), before)

        // Rolling forward again is the same operation.
        let forward = try await installer.rollbackToPrevious(in: paths)
        XCTAssertEqual(forward.version, "0.2.0")
        XCTAssertEqual(link(paths.currentRuntimeLink), "0.2.0")
        XCTAssertEqual(link(paths.previousRuntimeLink), "0.1.0")
    }

    func testRollbackToPreviousFailsClosedWithoutAPreviousRuntime() async throws {
        do {
            _ = try await installer.rollbackToPrevious(in: paths)
            XCTFail("nothing installed")
        } catch let error as RuntimeInstallError {
            XCTAssertEqual(error, .noPreviousRuntime)
        }

        try await install("0.1.0")

        do {
            _ = try await installer.rollbackToPrevious(in: paths)
            XCTFail("one version installed")
        } catch let error as RuntimeInstallError {
            XCTAssertEqual(error, .noPreviousRuntime)
        }

        // A dangling `previous` link is refused too, not followed.
        try await install("0.2.0")
        try RuntimeInstaller.makeWritable(paths.runtimeDirectory(version: "0.1.0"))
        try FileManager.default.removeItem(at: paths.runtimeDirectory(version: "0.1.0"))

        do {
            _ = try await installer.rollbackToPrevious(in: paths)
            XCTFail("previous directory is gone")
        } catch let error as RuntimeInstallError {
            XCTAssertEqual(error, .noPreviousRuntime)
        }

        XCTAssertEqual(link(paths.currentRuntimeLink), "0.2.0", "current is untouched by a refused rollback")
    }

    func testVersionsOlderThanPreviousAreGarbageCollected() async throws {
        try await install("0.1.0")
        try await install("0.2.0")
        try await install("0.3.0")

        XCTAssertFalse(exists(paths.runtimeDirectory(version: "0.1.0")), "older than previous is collected")
        XCTAssertTrue(exists(paths.runtimeDirectory(version: "0.2.0")))
        XCTAssertTrue(exists(paths.runtimeDirectory(version: "0.3.0")))
        XCTAssertEqual(link(paths.currentRuntimeLink), "0.3.0")
        XCTAssertEqual(link(paths.previousRuntimeLink), "0.2.0")

        let entries = try FileManager.default.contentsOfDirectory(atPath: paths.runtimeRoot.path).sorted()
        XCTAssertEqual(entries, ["0.2.0", "0.3.0", "current", "previous"], "no staging or temporary link survives")
    }

    func testReinstallingTheSameVersionReplacesTheRuntimeAndPreservesData() async throws {
        let before = try writeDatabase()
        try await install("0.1.0")
        let firstBinary = try Data(contentsOf: paths.runtimeDirectory(version: "0.1.0").appending(path: "frankenphp"))

        try await install("0.1.0", marker: " (reinstalled)")

        let secondBinary = try Data(contentsOf: paths.runtimeDirectory(version: "0.1.0").appending(path: "frankenphp"))
        XCTAssertNotEqual(firstBinary, secondBinary, "the directory was replaced, not merged")
        XCTAssertEqual(link(paths.currentRuntimeLink), "0.1.0")
        XCTAssertNil(link(paths.previousRuntimeLink), "a reinstall does not make the same version its own previous")
        XCTAssertEqual(try Data(contentsOf: paths.databaseURL), before)
        XCTAssertFalse(FileManager.default.isWritableFile(atPath: paths.runtimeDirectory(version: "0.1.0").appending(path: "frankenphp").path))
        XCTAssertNoThrow(try PackageVerifier(signatureChecker: AcceptAnySignatureChecker()).verify(runtimeDirectory: paths.runtimeDirectory(version: "0.1.0")))
    }

    func testReinstallKeepsAnExistingLogsDirectoryWithContent() async throws {
        try FileManager.default.createDirectory(at: paths.storageLogsLink, withIntermediateDirectories: true)
        try Data("old".utf8).write(to: paths.storageLogsLink.appending(path: "laravel.log"))

        try await install("0.1.0")

        XCTAssertNil(link(paths.storageLogsLink), "a real logs directory with content is never replaced by the symlink")
        XCTAssertEqual(try String(contentsOf: paths.storageLogsLink.appending(path: "laravel.log"), encoding: .utf8), "old")
    }

    // MARK: Partial install rollback

    func testRollbackOfAnInterruptedInstallRemovesStagingAndLeavesAnActivatedVersionAlone() async throws {
        try await install("0.1.0")

        let gate = FailAt(index: 2)
        let interrupted = RuntimeInstaller(backupExclusion: exclusion, beforeEachFile: { index in try await gate.check(index) })
        let handleBox = HandleBox()

        do {
            _ = try await interrupted.install(try package(version: "0.2.0"), into: paths, started: { handleBox.set($0) }, progress: { _ in })
            XCTFail("expected the injected failure")
        } catch let error as RuntimeInstallError {
            XCTAssertEqual(error, .copyFailed(path: "injected"))
        }

        let handle = try XCTUnwrap(handleBox.handle)
        XCTAssertTrue(exists(paths.stagingDirectory(version: "0.2.0")))

        try await interrupted.rollback(handle, in: paths)
        try await interrupted.rollback(handle, in: paths)

        XCTAssertFalse(exists(paths.stagingDirectory(version: "0.2.0")))
        XCTAssertFalse(exists(paths.runtimeDirectory(version: "0.2.0")))
        XCTAssertTrue(exists(paths.runtimeDirectory(version: "0.1.0")), "the activated version is never rolled back by a handle")
        XCTAssertEqual(link(paths.currentRuntimeLink), "0.1.0")
    }

    // MARK: Uninstall

    func testUninstallPreservingDataRemovesTheRuntimeAndKeepsTheDatabase() async throws {
        let before = try writeDatabase()
        try await install("0.1.0")
        try await install("0.2.0")
        try FileManager.default.createDirectory(at: paths.installDirectory, withIntermediateDirectories: true)
        try Data("{}".utf8).write(to: paths.checkpointURL)
        try Data("cache".utf8).write(to: paths.caches.appending(path: "x"))
        try Data("log".utf8).write(to: paths.logs.appending(path: "supervisor.log"))

        let report = try RuntimeUninstaller().remove(preservingData: true, in: paths)

        XCTAssertEqual(Set(report.removed.map(\.path)), Set([paths.runtimeRoot, paths.installDirectory, paths.caches, paths.logs].map(\.path)))
        XCTAssertEqual(report.preservedData, paths.dataRoot)
        XCTAssertFalse(exists(paths.runtimeRoot))
        XCTAssertFalse(exists(paths.installDirectory))
        XCTAssertFalse(exists(paths.caches))
        XCTAssertFalse(exists(paths.logs))
        XCTAssertTrue(exists(paths.dataRoot))
        XCTAssertEqual(try Data(contentsOf: paths.databaseURL), before)
        XCTAssertTrue(exists(paths.backupsDirectory))
        XCTAssertNil(link(paths.storageLogsLink), "no dangling symlink is left in the preserved data")
        XCTAssertTrue(exists(paths.storageLogsLink))

        // A later reinstall picks the preserved data up unchanged and re-links the logs.
        try await install("0.3.0")
        XCTAssertEqual(try Data(contentsOf: paths.databaseURL), before)
        XCTAssertEqual(link(paths.storageLogsLink), paths.logs.path)
    }

    func testUninstallRemovingDataLeavesNothingBehind() async throws {
        _ = try writeDatabase()
        try await install("0.1.0")
        try FileManager.default.createDirectory(at: paths.installDirectory, withIntermediateDirectories: true)
        try Data("{}".utf8).write(to: paths.checkpointURL)

        let report = try RuntimeUninstaller().remove(preservingData: false, in: paths)

        XCTAssertNil(report.preservedData)
        XCTAssertTrue(report.removed.contains(paths.dataRoot))
        XCTAssertFalse(exists(paths.dataRoot))
        XCTAssertFalse(exists(paths.runtimeRoot))
        XCTAssertFalse(exists(paths.applicationSupport), "the empty app-support directory goes too")
        XCTAssertFalse(exists(paths.logs))
        XCTAssertFalse(exists(paths.caches))

        let again = try RuntimeUninstaller().remove(preservingData: false, in: paths)
        XCTAssertEqual(again.removed, [], "idempotent")
    }

    // MARK: Environment contract (S1-07)

    func testSupervisorEnvironmentCarriesPathsAndPortAndNoSecret() async throws {
        let runtime = try await install("0.1.0")
        let environment = RuntimeEnvironment.supervisorEnvironment(runtime: runtime, paths: paths, port: 8788)

        XCTAssertEqual(environment["LEA_RUNTIME_DIR"], runtime.directory.path)
        XCTAssertEqual(environment["LEA_DATA_DIR"], paths.dataRoot.path)
        XCTAssertEqual(environment["LEA_CACHE_DIR"], paths.caches.path)
        XCTAssertEqual(environment["LEA_LOG_DIR"], paths.logs.path)
        XCTAssertEqual(environment["LEA_HTTP_PORT"], "8788")
        XCTAssertEqual(environment["PHPRC"], runtime.directory.path)
        XCTAssertEqual(environment["APP_ENV"], "production")
        XCTAssertEqual(environment["APP_DEBUG"], "false")
        // ADR-006 D3: the canonical browser origin, so a queued brief mail's CTA lands on the host
        // the session and the passkey live on. The listener bind is LEA_HTTP_PORT on 127.0.0.1.
        XCTAssertEqual(environment["APP_URL"], "http://\(PwaOrigin.host):8788")
        XCTAssertEqual(environment["APP_URL"], "http://localhost:8788")
        XCTAssertEqual(environment["LEA_BACKEND_PROFILE"], "local")
        XCTAssertEqual(environment.count, 10)

        for key in environment.keys {
            XCTAssertFalse(key.contains("KEY") || key.contains("TOKEN") || key.contains("SECRET") || key.contains("PASSWORD"), key)
        }

        XCTAssertNil(environment["APP_KEY"], "ADR-003: the app key reaches PHP through the broker, never the environment")
    }
}

// MARK: - Test doubles

private final class CheckpointCollector: Sendable {
    private let storage = Mutex<[InstallCoreCheckpoint]>([])

    func record(_ checkpoint: InstallCoreCheckpoint) {
        storage.withLock { $0.append(checkpoint) }
    }

    var checkpoints: [InstallCoreCheckpoint] {
        storage.withLock { $0 }
    }
}

private final class HandleBox: Sendable {
    private let storage = Mutex<RuntimeInstallHandle?>(nil)

    func set(_ handle: RuntimeInstallHandle) {
        storage.withLock { $0 = handle }
    }

    var handle: RuntimeInstallHandle? {
        storage.withLock { $0 }
    }
}

private struct FailAt: Sendable {
    let index: Int

    func check(_ current: Int) async throws {
        if current == index {
            throw RuntimeInstallError.copyFailed(path: "injected")
        }
    }
}
