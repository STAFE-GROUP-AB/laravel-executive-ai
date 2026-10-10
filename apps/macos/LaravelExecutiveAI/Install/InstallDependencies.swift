// Everything the installer state machine talks to, in one injectable container (S1-05).
//
// `live` is what the app runs. Where a later item owns the implementation, the live container
// carries the fail-closed stand-in: the install stops at that state with an actionable error
// instead of pretending. `simulated` (DEBUG only, `LXAI_INSTALL_SIMULATE=1`) walks the whole
// flow against doubles in a temporary directory so the six screens can be exercised locally.

import Foundation

struct InstallDependencies: Sendable {
    var paths: InstallPaths
    var productVersion: String
    var language: PreflightLanguage
    /// `Contents/Resources/runtime/`: the directory `packaging/runtime/assemble-runtime.mjs`
    /// produced, copied into the bundle by the S1-12 build (packaging/runtime/README.md §5).
    var bundledRuntimeDirectory: URL?
    var preflightProbes: any PreflightProbes
    var packageVerifier: PackageVerifier
    var runtimeInstaller: any RuntimeInstalling
    var ownerCreator: any OwnerCreating
    var aiSelectionApplier: any AiSelectionApplying
    var localAiRuntimeDetector: any LocalAiRuntimeDetecting
    var serviceBooter: any ServiceBooting
    var healthProbe: any HealthProbe
    var healthPolicy: HealthPollingPolicy
    var checkpointStore: any InstallCheckpointStore
    var clock: any InstallClock
    var timeZone: TimeZone
    /// Where the event log for a run goes; nil keeps it in memory.
    var eventLogURL: @Sendable (UUID) -> URL?

    /// ADR-001 D3 LaunchAgent plist name; prefix pending A12.
    static func supervisorPlistName(bundlePrefix: String) -> String {
        "\(bundlePrefix).supervisor.plist"
    }

    static func live(bundle: Bundle = .main) -> InstallDependencies {
        let bundleIdentifier = bundle.bundleIdentifier ?? "se.stafe.executive-ai.app"
        let bundlePrefix = bundleIdentifier.hasSuffix(".app") ? String(bundleIdentifier.dropLast(4)) : bundleIdentifier
        let paths = InstallPaths.default(bundleIdentifier: bundleIdentifier)
        let version = bundle.object(forInfoDictionaryKey: "CFBundleShortVersionString") as? String ?? "0.0.0"
        let runtimeDirectory = bundle.url(forResource: "runtime", withExtension: nil)
        let runtimeExecutable = runtimeDirectory?.appending(path: "frankenphp")

        let signatureChecker: any CodeSignatureChecking

        do {
            signatureChecker = try SecStaticCodeSignatureChecker.fromRunningApp()
        } catch {
            signatureChecker = FailClosedSignatureChecker(reason: error.userFacingReason)
        }

        // S1-07: BOOT_SERVICES registers the Supervisor LaunchAgent and hands the SELECT_AI key
        // to it over XPC; the installer itself never touches launchd state outside the
        // registrar nor the Keychain at all. S1-08: HEALTH_CHECK probes `/health/ready` through
        // the loopback IPC client with the process's one `menubar` session; the port is read
        // from the checkpoint when the probe runs, because preflight records it in this run.
        let pendingSecret = PendingSecretHandoff()
        let control = SupervisorControlClientFactory.live(bundle: bundle)
        let checkpointStore = FileInstallCheckpointStore(url: paths.checkpointURL)

        return InstallDependencies(
            paths: paths,
            productVersion: version,
            language: PreflightLanguage.preferred(),
            bundledRuntimeDirectory: runtimeDirectory,
            preflightProbes: SystemPreflightProbes(
                bundle: bundle,
                bundledRuntimeExecutable: runtimeExecutable.flatMap { FileManager.default.fileExists(atPath: $0.path) ? $0 : nil },
                supervisorPlistName: supervisorPlistName(bundlePrefix: bundlePrefix)
            ),
            packageVerifier: PackageVerifier(signatureChecker: signatureChecker),
            runtimeInstaller: RuntimeInstaller(backupExclusion: TimeMachineBackupExclusion()),
            ownerCreator: UnavailableOwnerCreator(),
            aiSelectionApplier: SupervisorAiSelectionApplier(pending: pendingSecret),
            localAiRuntimeDetector: FileSystemLocalAiRuntimeDetector(),
            serviceBooter: LaunchAgentServiceBooter(
                registrar: SMAppServiceRegistrar(plistName: supervisorPlistName(bundlePrefix: bundlePrefix)),
                control: control,
                pending: pendingSecret
            ),
            healthProbe: IpcClientFactory.makeHealthProbe(bundle: bundle, checkpointStore: checkpointStore, session: IpcClientFactory.liveSession),
            healthPolicy: HealthPollingPolicy(),
            checkpointStore: checkpointStore,
            clock: MonotonicWallClock(),
            timeZone: .current,
            eventLogURL: { runID in paths.eventLogURL(runID: runID) }
        )
    }

    #if DEBUG
    /// A complete walk through the flow against doubles, under a temporary root. Nothing it does
    /// touches the real user-data directory, the Keychain, launchd or the network.
    static func simulated(root: URL) throws -> InstallDependencies {
        let paths = InstallPaths.rooted(at: root)
        let package = try SimulatedRuntimePackage.write(under: root.appending(path: "bundle-runtime"))

        return InstallDependencies(
            paths: paths,
            productVersion: "0.0.0",
            language: PreflightLanguage.preferred(),
            bundledRuntimeDirectory: package,
            preflightProbes: SimulatedPreflightProbes(),
            packageVerifier: PackageVerifier(signatureChecker: AcceptAnySignatureChecker()),
            runtimeInstaller: RuntimeInstaller(
                backupExclusion: RecordingBackupExclusion(),
                beforeEachFile: { _ in try await Task.sleep(for: .milliseconds(250)) }
            ),
            ownerCreator: ScriptedOwnerCreator(),
            aiSelectionApplier: ScriptedAiSelectionApplier(),
            localAiRuntimeDetector: FileSystemLocalAiRuntimeDetector(),
            serviceBooter: ScriptedServiceBooter(),
            healthProbe: ScriptedHealthProbe([
                .success(HealthReadiness(ready: false, failingChecks: ["queue_worker", "scheduler_selftest"], aiState: "not_configured")),
                .success(.coreReadyNoAI),
            ]),
            healthPolicy: HealthPollingPolicy(maxAttempts: 10, interval: .milliseconds(600)),
            checkpointStore: FileInstallCheckpointStore(url: paths.checkpointURL),
            clock: MonotonicWallClock(),
            timeZone: .current,
            eventLogURL: { runID in paths.eventLogURL(runID: runID) }
        )
    }
    #endif
}

/// Refuses every binary. Used when the running app has no Team ID, so an unsigned build can
/// never install a runtime (support matrix §6.10, threat model T-47).
struct FailClosedSignatureChecker: CodeSignatureChecking {
    let reason: String

    func validate(executableAt url: URL) throws {
        throw PackageVerificationError.noSigningPolicy(reason)
    }
}

#if DEBUG
/// Simulation and tests only. Never wired into `live`.
struct AcceptAnySignatureChecker: CodeSignatureChecking {
    func validate(executableAt url: URL) throws {}
}

/// Probes that answer like a supported Mac, for the simulation.
struct SimulatedPreflightProbes: PreflightProbes {
    func operatingSystemVersion() -> OperatingSystemVersion { ProcessInfo.processInfo.operatingSystemVersion }
    func operatingSystemBuild() -> String { "simulated" }
    func isArm64() -> Bool { true }
    func isTranslated() -> Bool { false }
    func hardwareModel() -> String { "simulated" }
    func cpuBrand() -> String { "simulated" }
    func physicalMemoryBytes() -> UInt64 { 16 * 1024 * 1024 * 1024 }
    func availableCapacityBytes(forVolumeContaining url: URL) -> Int64? { 50_000_000_000 }
    func canWriteApplicationSupport(at url: URL) -> Bool { true }
    func sessionUser() -> SessionUser { SessionUser(name: "simulated", isConsoleUser: true, isGuest: false, isRoot: false) }
    func canBindLoopback(port: UInt16) -> Bool { true }
    func networkState() async -> NetworkState { .online }
    func existingInstall(paths: InstallPaths) -> ExistingInstallFacts { .none }
    func fileVaultState() -> FileVaultState { .off }
    func signedExecutables() -> [SignedExecutableResult] { [SignedExecutableResult(name: "app", valid: true, detail: "")] }
}

/// Writes a small, internally consistent runtime package: manifest, SHA256SUMS and files.
/// Shared by the simulation and the tests.
enum SimulatedRuntimePackage {
    static func write(
        under directory: URL,
        version: String = "0.1.0",
        files: [(path: String, contents: Data)]? = nil
    ) throws -> URL {
        let fileManager = FileManager.default

        if fileManager.fileExists(atPath: directory.path) {
            try fileManager.removeItem(at: directory)
        }

        try fileManager.createDirectory(at: directory, withIntermediateDirectories: true)

        let contents = files ?? [
            ("frankenphp", Data("#!/bin/sh\necho simulated runtime\n".utf8)),
            ("Caddyfile", Data("bind 127.0.0.1\n".utf8)),
            ("php.ini", Data("opcache.jit=disable\n".utf8)),
            ("app/public/index.php", Data("<?php echo 'ok';\n".utf8)),
        ]

        var entries: [RuntimeManifest.Entry] = []
        var sums = ""

        for file in contents {
            let url = directory.appending(path: file.path)
            try fileManager.createDirectory(at: url.deletingLastPathComponent(), withIntermediateDirectories: true)
            try file.contents.write(to: url)

            let digest = try PackageVerifier.sha256Hex(of: url)
            entries.append(RuntimeManifest.Entry(path: file.path, sha256: digest, bytes: Int64(file.contents.count)))
            sums += "\(digest)  \(file.path)\n"
        }

        let manifest = RuntimeManifest(schemaVersion: 1, runtimeVersion: version, executable: contents[0].path, files: entries)
        let encoder = JSONEncoder()
        encoder.outputFormatting = [.prettyPrinted, .sortedKeys]
        try encoder.encode(manifest).write(to: directory.appending(path: PackageVerifier.manifestFileName))
        try Data(sums.utf8).write(to: directory.appending(path: PackageVerifier.checksumFileName))

        return directory
    }
}
#endif
