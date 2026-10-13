// S1-13 (§12.2 uninstall and data-preserving reinstall; ADR-003 D8; threat model T-41, T-72).
//
// Everything runs under a temporary root (`InstallPaths.rooted(at:)`) against the recording
// doubles: `RecordingRegistrar` for launchd, `InMemoryKeychainStore` behind a
// `ScriptedSupervisorControl` for the Keychain sweep, `RecordingAppBundleRemover` for the Trash.
// Nothing here registers an agent, touches the real Keychain, opens a Mach service or trashes a
// bundle; the signed clean-machine run is S1-14's lab step (docs/operations/uninstall.md §6).

import Foundation
import XCTest
@testable import LaravelExecutiveAI

final class UninstallerTests: XCTestCase {
    private var root: URL!
    private var paths: InstallPaths!

    override func setUpWithError() throws {
        root = FileManager.default.temporaryDirectory.appending(path: "lxai-uninstall-\(UUID().uuidString)")
        try FileManager.default.createDirectory(at: root, withIntermediateDirectories: true)
        paths = InstallPaths.rooted(at: root)
    }

    override func tearDownWithError() throws {
        try? RuntimeInstaller.makeWritable(root)
        try? FileManager.default.removeItem(at: root)
    }

    // MARK: - Harness

    /// One installed app: a runtime, a database, a checkpoint with an owner, a brief cache, a
    /// `run/` socket directory, logs, and three Keychain items plus one that is not ours.
    struct Harness {
        let keychain = InMemoryKeychainStore()
        let registrar: RecordingRegistrar
        let control: ScriptedSupervisorControl
        let remover: RecordingAppBundleRemover
        let journalStore: InMemoryUninstallJournalStore
        let checkpointStore: FileInstallCheckpointStore
        let briefCache: BriefCache
        let bundleURL = URL(fileURLWithPath: "/Applications/Example.app")
        let foreignItem = KeychainItemRef(service: "com.example.unrelated", account: "other")
        /// The envelope the first boot generated, so a keep-data test can prove the item that
        /// survives the sweep is that same key and not a regenerated one (A25).
        let appKey: AppKeyEnvelope
        var dependencies: UninstallDependencies

        init(paths: InstallPaths, registrarStatus: LaunchAgentStatus = .enabled, control: ScriptedSupervisorControl? = nil, remover: RecordingAppBundleRemover = RecordingAppBundleRemover()) async throws {
            let fileManager = FileManager.default
            let secrets = SupervisorSecrets(store: keychain)
            appKey = try secrets.ensureAppKey()
            _ = try secrets.ensureIpcKey()
            try secrets.storeProviderApiKey(provider: "openai", apiKey: TransientSecret("sk-test-not-a-real-key-000000000000")!)
            try keychain.write(foreignItem, data: Data("keep".utf8), label: "unrelated")

            registrar = RecordingRegistrar(statuses: [registrarStatus])
            self.control = control ?? ScriptedSupervisorControl(secrets: secrets)
            self.remover = remover
            journalStore = InMemoryUninstallJournalStore()
            checkpointStore = FileInstallCheckpointStore(url: paths.checkpointURL)
            briefCache = BriefCache(paths: paths)

            // The runtime, as the real installer lays it out.
            let package = try SimulatedRuntimePackage.write(under: paths.applicationSupport.deletingLastPathComponent().appending(path: "bundle-runtime"))
            let verified = try PackageVerifier(signatureChecker: AcceptAnySignatureChecker()).verify(runtimeDirectory: package)
            _ = try await RuntimeInstaller(backupExclusion: RecordingBackupExclusion()).install(verified, into: paths, started: { _ in }, progress: { _ in })

            try Data("sqlite".utf8).write(to: paths.databaseURL)
            try Data("backup".utf8).write(to: paths.backupsDirectory.appending(path: "snapshot.sqlite"))
            try fileManager.createDirectory(at: paths.runDirectory, withIntermediateDirectories: true)
            try Data().write(to: paths.runDirectory.appending(path: "secrets.sock"))
            try Data("log".utf8).write(to: paths.logs.appending(path: "supervisor.log"))
            try briefCache.store(document: .object(["id": .string("x")]), etag: "\"x:1\"")

            var checkpoint = InstallCheckpoint(updatedAt: Date())
            checkpoint.lastCompletedState = .ready
            checkpoint.ownerCreated = true
            try checkpointStore.save(checkpoint)

            dependencies = UninstallDependencies(
                paths: paths,
                productVersion: "0.1.0",
                control: self.control,
                registrar: registrar,
                briefCache: briefCache,
                checkpointStore: checkpointStore,
                journalStore: journalStore,
                appBundleRemover: remover,
                appBundleURL: bundleURL,
                now: { Date(timeIntervalSince1970: 1_791_648_000) },
                afterStep: nil
            )
        }

        var uninstaller: Uninstaller {
            Uninstaller(dependencies: dependencies)
        }

        var inventoryItems: [KeychainItemRef] {
            keychain.snapshot.keys.filter { SecretService.inventory.contains($0.service) }
        }
    }

    private func exists(_ url: URL) -> Bool {
        FileManager.default.fileExists(atPath: url.path) || (try? FileManager.default.destinationOfSymbolicLink(atPath: url.path)) != nil
    }

    // MARK: - Nothing left behind (T-41, §12.2)

    func testDeleteEverythingLeavesNothingBehind() async throws {
        let harness = try await Harness(paths: paths)

        let report = try await harness.uninstaller.uninstall(choice: .delete)

        // The order: stop → sweep through the Supervisor → unregister → files → trash.
        XCTAssertEqual(harness.control.calls, [.stopAll, .removeAllSecrets(keepApplicationKey: false)])
        XCTAssertEqual(harness.registrar.calls.filter { $0 == .unregister }, [.unregister])
        XCTAssertEqual(report.performed, UninstallStep.allCases)
        XCTAssertEqual(report.resumedFrom, [])
        XCTAssertEqual(report.secretsSweep, .removed(count: 3))
        XCTAssertNil(report.preservedData)
        XCTAssertTrue(report.appBundleTrashed)

        // Application Support, Caches and Logs for the bundle are gone.
        XCTAssertFalse(exists(paths.applicationSupport))
        XCTAssertFalse(exists(paths.caches))
        XCTAssertFalse(exists(paths.logs))
        XCTAssertNil(harness.briefCache.load())
        XCTAssertFalse(exists(harness.briefCache.fileURL))

        // launchd and the Keychain.
        XCTAssertEqual(harness.registrar.status(), .notRegistered)
        XCTAssertEqual(harness.inventoryItems, [], "every se.stafe.executive-ai.* item is gone")
        XCTAssertNil(harness.keychain.snapshot[SupervisorSecrets.appKeyRef], "A25 spares the application key only when data is kept; this choice takes it too")
        XCTAssertEqual(Array(harness.keychain.snapshot.keys), [harness.foreignItem], "an item that is not ours is untouched")

        // The bundle, exactly once, last.
        XCTAssertEqual(harness.remover.trashed, [harness.bundleURL])

        // Nothing is journaled afterwards.
        XCTAssertNil(try harness.journalStore.load())
    }

    func testDeleteEverythingIsIdempotent() async throws {
        let harness = try await Harness(paths: paths)
        _ = try await harness.uninstaller.uninstall(choice: .delete)

        let again = try await harness.uninstaller.uninstall(choice: .delete)

        XCTAssertEqual(harness.control.calls, [.stopAll, .removeAllSecrets(keepApplicationKey: false)], "the Supervisor is not called again once the agent is unregistered")
        XCTAssertEqual(harness.registrar.calls.filter { $0 == .unregister }.count, 1)
        XCTAssertEqual(harness.remover.trashed.count, 2, "trashing again is harmless; the Finder ignores a missing item")
        XCTAssertEqual(again.secretsSweep.status, .skipped, "with no Supervisor registered there is nothing to sweep, and the report says so")
        XCTAssertFalse(exists(paths.applicationSupport))
    }

    // MARK: - Keep data (§12.2 data-preserving reinstall)

    func testKeepDataPreservesTheDataDirectoryAndWritesTheReinstallMarker() async throws {
        let harness = try await Harness(paths: paths)
        let database = try Data(contentsOf: paths.databaseURL)

        let report = try await harness.uninstaller.uninstall(choice: .keep)

        XCTAssertEqual(report.preservedData, paths.dataRoot)
        XCTAssertEqual(harness.control.calls, [.stopAll, .removeAllSecrets(keepApplicationKey: true)])
        XCTAssertEqual(harness.registrar.status(), .notRegistered)
        XCTAssertEqual(harness.remover.trashed, [harness.bundleURL])

        // A25 (ADR-003 D8, "every item except the application key when data is kept"): `K` and the
        // provider key go, `core.app-key` stays — it is the only thing that can read the rows under
        // the `data/` tree this choice keeps (S2-04's `encrypted:` casts), and the count the sheet
        // shows is what was actually removed, so the spared key is not in it.
        XCTAssertEqual(harness.inventoryItems, [SupervisorSecrets.appKeyRef])
        XCTAssertEqual(report.secretsSweep, .removed(count: 2))
        XCTAssertEqual(Set(harness.keychain.snapshot.keys), [SupervisorSecrets.appKeyRef, harness.foreignItem], "an item that is not ours is untouched either way")
        XCTAssertEqual(try SupervisorSecrets(store: harness.keychain).ensureAppKey(), harness.appKey, "the same key, not a freshly generated one: the preserved database still decrypts")

        // Kept: the database, the backups, the storage tree — with a real logs directory in
        // place of the symlink into the removed Logs directory.
        XCTAssertEqual(try Data(contentsOf: paths.databaseURL), database)
        XCTAssertTrue(exists(paths.backupsDirectory.appending(path: "snapshot.sqlite")))
        XCTAssertNil(try? FileManager.default.destinationOfSymbolicLink(atPath: paths.storageLogsLink.path))
        XCTAssertTrue(exists(paths.storageLogsLink))

        // Removed: runtime, run/, Caches, Logs, the brief cache, the journal.
        XCTAssertFalse(exists(paths.runtimeRoot))
        XCTAssertFalse(exists(paths.runDirectory))
        XCTAssertFalse(exists(paths.caches))
        XCTAssertFalse(exists(paths.logs))
        XCTAssertNil(harness.briefCache.load())
        XCTAssertNil(try harness.journalStore.load())

        // The marker: no completed state (so the next run starts at PREFLIGHT and installs the
        // runtime again), owner_created carried over (so CREATE_OWNER is skipped).
        let marker = try XCTUnwrap(try harness.checkpointStore.load())
        XCTAssertNil(marker.lastCompletedState)
        XCTAssertEqual(marker.resumeState, .preflight)
        XCTAssertTrue(marker.ownerCreated)
        XCTAssertFalse(marker.isReady)
        XCTAssertEqual(marker.uninstalledPreservingData?.ownerCreated, true)
        XCTAssertEqual(marker.uninstalledPreservingData?.productVersion, "0.1.0")
        XCTAssertNil(marker.preflight)
        XCTAssertNil(marker.runtime)
        XCTAssertNil(marker.aiSelection)

        let json = String(decoding: try Data(contentsOf: paths.checkpointURL), as: UTF8.self)
        XCTAssertTrue(json.contains("\"uninstalled_preserving_data\""))
        XCTAssertTrue(json.contains("\"owner_created\" : true"))
        XCTAssertFalse(json.contains("sk-test"))
    }

    @MainActor
    func testReinstallOverPreservedDataSkipsCreateOwner() async throws {
        let harness = try await Harness(paths: paths)
        _ = try await harness.uninstaller.uninstall(choice: .keep)
        let database = try Data(contentsOf: paths.databaseURL)

        // A new run of the installer against the preserved tree: the marker is its checkpoint.
        var installHarness = try InstallHarness(checkpoint: try harness.checkpointStore.load())
        installHarness.dependencies.paths = paths
        let machine = installHarness.makeMachine()
        defer { installHarness.tearDown() }

        XCTAssertFalse(machine.canResume, "a marker is not a half-done install; it is a fresh run with facts carried over")

        machine.start()
        await machine.proceedFromWelcome()

        XCTAssertEqual(machine.state, .selectAI, "PREFLIGHT, PACKAGE_VERIFY and INSTALL_CORE ran; CREATE_OWNER was skipped because the owner already exists in the preserved data")
        XCTAssertEqual(installHarness.ownerCreator.drafts, [], "no owner draft is ever submitted on a reinstall")
        XCTAssertEqual(try Data(contentsOf: paths.databaseURL), database, "data/ is byte-identical after the reinstall")
        XCTAssertEqual(try? FileManager.default.destinationOfSymbolicLink(atPath: paths.storageLogsLink.path), paths.logs.path, "the logs symlink is re-linked by the install")
        XCTAssertNotNil(paths.currentRuntimeVersion())
    }

    // MARK: - Resumable and retry-safe

    func testAnInterruptedUninstallResumesWithoutRepeatingAStep() async throws {
        for interruptAfter in UninstallStep.allCases.dropLast() {
            try tearDownWithError()
            try setUpWithError()

            var harness = try await Harness(paths: paths)
            harness.dependencies.afterStep = { step in
                if step == interruptAfter {
                    throw CancellationError()
                }
            }

            do {
                _ = try await harness.uninstaller.uninstall(choice: .delete)
                XCTFail("\(interruptAfter): expected the simulated crash")
            } catch is CancellationError {}

            let journal = try harness.journalStore.load()
            let completedBeforeCrash = Array(UninstallStep.allCases.prefix(through: UninstallStep.allCases.firstIndex(of: interruptAfter)!))

            // On the delete path step 6 removes the directory the journal lives in, so it is the
            // one step that is not journaled; a resumed run repeats it (idempotently) and nothing
            // before it.
            let journaled = interruptAfter == .installDirectoryFinalized ? Array(completedBeforeCrash.dropLast()) : completedBeforeCrash
            XCTAssertEqual(journal?.completed, journaled, "\(interruptAfter): the journal records exactly what finished")

            harness.dependencies.afterStep = nil
            let report = try await harness.uninstaller.uninstall(choice: .delete)

            XCTAssertEqual(report.resumedFrom, journaled, "\(interruptAfter)")
            XCTAssertEqual(report.performed, Array(UninstallStep.allCases.dropFirst(journaled.count)), "\(interruptAfter): the second run does only what is left")
            XCTAssertEqual(harness.control.calls, [.stopAll, .removeAllSecrets(keepApplicationKey: false)], "\(interruptAfter): the Supervisor is stopped and swept exactly once")
            XCTAssertEqual(harness.registrar.calls.filter { $0 == .unregister }.count, 1, "\(interruptAfter): unregistered exactly once")
            XCTAssertEqual(harness.remover.trashed, [harness.bundleURL], "\(interruptAfter): trashed exactly once")
            XCTAssertEqual(harness.inventoryItems, [], "\(interruptAfter)")
            XCTAssertFalse(exists(paths.applicationSupport), "\(interruptAfter)")
            XCTAssertFalse(exists(paths.caches), "\(interruptAfter)")
            XCTAssertFalse(exists(paths.logs), "\(interruptAfter)")
            XCTAssertNil(try harness.journalStore.load(), "\(interruptAfter)")
        }
    }

    func testAFailedUnregisterIsRetriedWithoutSweepingTheKeychainTwice() async throws {
        var harness = try await Harness(paths: paths)
        let failing = RecordingRegistrar(statuses: [.enabled], unregisterError: .unregistrationFailed("SMAppServiceErrorDomain 1"))
        harness.dependencies.registrar = failing

        do {
            _ = try await harness.uninstaller.uninstall(choice: .keep)
            XCTFail("expected failure")
        } catch let error as UninstallError {
            XCTAssertEqual(error, .unregisterFailed("the launch agent could not be unregistered"))
        }

        XCTAssertEqual(try harness.journalStore.load()?.completed, [.servicesStopped, .secretsRemoved])
        XCTAssertTrue(exists(paths.runtimeRoot), "nothing on disk is touched before launchd is done")
        XCTAssertEqual(harness.remover.trashed, [])

        harness.dependencies.registrar = harness.registrar
        let report = try await harness.uninstaller.uninstall(choice: .keep)

        XCTAssertEqual(report.resumedFrom, [.servicesStopped, .secretsRemoved])
        XCTAssertEqual(harness.control.calls, [.stopAll, .removeAllSecrets(keepApplicationKey: true)], "the sweep is not repeated")
        XCTAssertEqual(report.secretsSweep, .removed(count: 2), "the first run's sweep result is carried in the journal — two items, because this keep-data run spared the application key (A25)")
        XCTAssertEqual(harness.registrar.status(), .notRegistered)
        XCTAssertFalse(exists(paths.runtimeRoot))
        XCTAssertTrue(exists(paths.dataRoot))
    }

    func testChangingTheDataChoiceOnRetryRemovesTheDataAfterAll() async throws {
        var harness = try await Harness(paths: paths)
        harness.dependencies.afterStep = { step in
            if step == .briefCacheCleared {
                throw CancellationError()
            }
        }

        do {
            _ = try await harness.uninstaller.uninstall(choice: .keep)
        } catch is CancellationError {}

        XCTAssertTrue(exists(paths.dataRoot))

        harness.dependencies.afterStep = nil
        let report = try await harness.uninstaller.uninstall(choice: .delete)

        XCTAssertEqual(report.dataChoice, .delete)
        XCTAssertTrue(report.performed.contains(.runtimeRemoved), "the file-system step runs again under the new choice")
        XCTAssertFalse(exists(paths.dataRoot))
        XCTAssertFalse(exists(paths.applicationSupport))

        // The crash came after step 3, so the Supervisor that holds the access group is already
        // unregistered: the keep-data sweep cannot be repeated and the `core.app-key` it spared
        // (A25) outlives the uninstall. The journal keeps the honest record of the run that did
        // sweep rather than claiming a second one.
        XCTAssertEqual(harness.control.calls, [.stopAll, .removeAllSecrets(keepApplicationKey: true)])
        XCTAssertEqual(harness.inventoryItems, [SupervisorSecrets.appKeyRef])
        XCTAssertEqual(report.secretsSweep, .removed(count: 2))
    }

    /// The same change of mind, but before step 3: the Supervisor is still there, so the key the
    /// keep-data sweep spared is collected after all (A25 — "a delete-everything uninstall deletes
    /// the key too").
    func testChangingTheDataChoiceBeforeUnregisteringSweepsTheApplicationKeyAfterAll() async throws {
        var harness = try await Harness(paths: paths)
        harness.dependencies.afterStep = { step in
            if step == .secretsRemoved {
                throw CancellationError()
            }
        }

        do {
            _ = try await harness.uninstaller.uninstall(choice: .keep)
            XCTFail("expected the simulated crash")
        } catch is CancellationError {}

        XCTAssertEqual(harness.inventoryItems, [SupervisorSecrets.appKeyRef], "the keep-data sweep spared exactly the application key")

        harness.dependencies.afterStep = nil
        let report = try await harness.uninstaller.uninstall(choice: .delete)

        XCTAssertEqual(harness.control.calls, [.stopAll, .removeAllSecrets(keepApplicationKey: true), .removeAllSecrets(keepApplicationKey: false)])
        XCTAssertTrue(report.performed.contains(.secretsRemoved), "the sweep runs again under the new choice")
        XCTAssertEqual(report.secretsSweep, .removed(count: 1), "only the one item that was left")
        XCTAssertEqual(harness.inventoryItems, [], "nothing of ours is left behind (T-41)")
        XCTAssertEqual(Array(harness.keychain.snapshot.keys), [harness.foreignItem])
        XCTAssertEqual(harness.registrar.calls.filter { $0 == .unregister }.count, 1, "unregistered exactly once")
        XCTAssertFalse(exists(paths.dataRoot))
    }

    // MARK: - Fail closed

    func testAnUnreachableSupervisorWithAnEnabledAgentStopsBeforeAnythingIsTouched() async throws {
        let harness = try await Harness(paths: paths, control: ScriptedSupervisorControl(stopAllError: .unreachable("timeout")))

        do {
            _ = try await harness.uninstaller.uninstall(choice: .delete)
            XCTFail("expected failure")
        } catch let error as UninstallError {
            XCTAssertEqual(error, .supervisorUnreachable("unreachable"))
            XCTAssertFalse(error.userFacingReason.contains("/"))
        }

        XCTAssertEqual(harness.registrar.calls.filter { $0 == .unregister }, [])
        XCTAssertEqual(harness.inventoryItems.count, 3, "no Keychain item was touched")
        XCTAssertTrue(exists(paths.runtimeRoot))
        XCTAssertTrue(exists(paths.dataRoot))
        XCTAssertEqual(harness.remover.trashed, [])
        XCTAssertEqual(try harness.journalStore.load()?.completed, [], "the journal exists but records nothing done")
    }

    func testASweepRefusedByTheSupervisorStopsBeforeUnregistering() async throws {
        let harness = try await Harness(paths: paths, control: ScriptedSupervisorControl(removeSecretsError: .secrets("secrets")))

        do {
            _ = try await harness.uninstaller.uninstall(choice: .delete)
            XCTFail("expected failure")
        } catch let error as UninstallError {
            XCTAssertEqual(error, .secretsFailed("secrets"))
        }

        XCTAssertEqual(harness.control.calls, [.stopAll, .removeAllSecrets(keepApplicationKey: false)])
        XCTAssertEqual(harness.registrar.status(), .enabled, "the agent stays so the sweep can be retried through the Supervisor")
        XCTAssertTrue(exists(paths.runtimeRoot))
        XCTAssertEqual(harness.remover.trashed, [])
    }

    func testWithoutARegisteredAgentTheSweepIsSkippedAndReportedHonestly() async throws {
        let harness = try await Harness(paths: paths, registrarStatus: .notRegistered)

        let report = try await harness.uninstaller.uninstall(choice: .delete)

        XCTAssertEqual(harness.control.calls, [], "no Supervisor can be running under an unregistered agent")
        XCTAssertEqual(harness.registrar.calls.filter { $0 == .unregister }, [])
        XCTAssertEqual(report.secretsSweep, .skipped(reason: "launch agent notRegistered"))
        XCTAssertEqual(harness.inventoryItems.count, 3, "the items are reported as not swept rather than silently claimed")
        XCTAssertFalse(exists(paths.applicationSupport))
        XCTAssertEqual(harness.remover.trashed, [harness.bundleURL])
    }

    func testABuildWithoutABundleLeavesNothingToTrash() async throws {
        var harness = try await Harness(paths: paths)
        harness.dependencies.appBundleURL = nil

        let report = try await harness.uninstaller.uninstall(choice: .delete)

        XCTAssertFalse(report.appBundleTrashed)
        XCTAssertEqual(harness.remover.trashed, [])
        XCTAssertFalse(exists(paths.applicationSupport))
    }

    // MARK: - Journal file

    func testJournalFileIsSnakeCaseJsonWithoutASecret() throws {
        let store = FileUninstallJournalStore(url: paths.uninstallJournalURL)
        var journal = UninstallJournal(startedAt: Date(timeIntervalSince1970: 1_791_648_000), dataChoice: .keep, ownerCreated: true, productVersion: "0.1.0")
        journal.completed = [.servicesStopped, .secretsRemoved]
        journal.secretsSweep = .removed(count: 3)

        try store.save(journal)

        let attributes = try FileManager.default.attributesOfItem(atPath: paths.uninstallJournalURL.path)
        XCTAssertEqual((attributes[.posixPermissions] as? NSNumber)?.intValue, 0o600)

        let text = String(decoding: try Data(contentsOf: paths.uninstallJournalURL), as: UTF8.self)

        for key in ["\"schema_version\" : 1", "\"run_id\"", "\"started_at\"", "\"data_choice\" : \"keep\"", "\"services_stopped\"", "\"secrets_removed\"", "\"owner_created\" : true", "\"product_version\" : \"0.1.0\"", "\"removed_count\" : 3", "\"status\" : \"removed\""] {
            XCTAssertTrue(text.contains(key), "missing \(key) in \(text)")
        }

        XCTAssertEqual(try store.load(), journal)

        try store.clear()
        XCTAssertNil(try store.load())
        try store.clear()
    }

    func testJournalWithAnotherSchemaVersionIsIgnoredNotTrusted() throws {
        try FileManager.default.createDirectory(at: paths.installDirectory, withIntermediateDirectories: true)
        try Data("{\"schema_version\":2,\"completed\":[\"secrets_removed\"]}".utf8).write(to: paths.uninstallJournalURL)

        XCTAssertNil(try FileUninstallJournalStore(url: paths.uninstallJournalURL).load())
    }
}
