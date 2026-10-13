// Shared doubles and builders for the S1-05 installer tests. Everything runs under a temporary
// root; nothing touches the real user-data directory, launchd, the Keychain or the network.

import Foundation
import XCTest
@testable import LaravelExecutiveAI

/// Preflight probes with every fact configurable; defaults describe a supported Mac.
struct StubPreflightProbes: PreflightProbes {
    var osVersion = OperatingSystemVersion(majorVersion: 26, minorVersion: 6, patchVersion: 2)
    var build = "25G83"
    var arm64 = true
    var translated = false
    var memory: UInt64 = 16 * 1024 * 1024 * 1024
    var availableDisk: Int64? = 100_000_000_000
    var writable = true
    var user = SessionUser(name: "tester", isConsoleUser: true, isGuest: false, isRoot: false)
    var takenPorts: Set<UInt16> = []
    var network = NetworkState.online
    var existing = ExistingInstallFacts.none
    var fileVault = FileVaultState.on
    var signatures = [SignedExecutableResult(name: "app", valid: true, detail: "")]

    func operatingSystemVersion() -> OperatingSystemVersion { osVersion }
    func operatingSystemBuild() -> String { build }
    func isArm64() -> Bool { arm64 }
    func isTranslated() -> Bool { translated }
    func hardwareModel() -> String { "Mac16,9" }
    func cpuBrand() -> String { "Apple M4 Max" }
    func physicalMemoryBytes() -> UInt64 { memory }
    func availableCapacityBytes(forVolumeContaining url: URL) -> Int64? { availableDisk }
    func canWriteApplicationSupport(at url: URL) -> Bool { writable }
    func sessionUser() -> SessionUser { user }
    func canBindLoopback(port: UInt16) -> Bool { !takenPorts.contains(port) }
    func networkState() async -> NetworkState { network }
    func existingInstall(paths: InstallPaths) -> ExistingInstallFacts { existing }
    func fileVaultState() -> FileVaultState { fileVault }
    func signedExecutables() -> [SignedExecutableResult] { signatures }
}

/// A gate the doubles can block on until a test opens it, or that hangs until cancelled.
actor TestGate {
    private var opened = false
    private var waiters: [CheckedContinuation<Void, Never>] = []

    func open() {
        opened = true
        waiters.forEach { $0.resume() }
        waiters.removeAll()
    }

    func wait() async throws {
        if !opened {
            await withCheckedContinuation { waiters.append($0) }
        }

        try Task.checkCancellation()
    }
}

/// Hangs until cancelled. Used to park an automated state so cancel() can be exercised there.
func hangUntilCancelled() async throws {
    try await Task.sleep(for: .seconds(300))
}

final class HangingHealthProbe: HealthProbe {
    func readiness() async throws -> HealthReadiness {
        try await hangUntilCancelled()
        return .coreReadyNoAI
    }
}

final class HangingServiceBooter: ServiceBooting {
    /// INSTALL_CORE's half returns at once: the tests that use this park the machine in
    /// BOOT_SERVICES, which is the half below.
    func startSupervisor(runtime: InstalledRuntime, port: Int) async throws -> BootServicesOutcome {
        .started
    }

    func boot(runtime: InstalledRuntime, port: Int) async throws -> BootServicesOutcome {
        try await hangUntilCancelled()
        return .started
    }

    func openLoginItemsSettings() {}
}

struct HangingPreflightProbes: PreflightProbes {
    let base = StubPreflightProbes()

    func operatingSystemVersion() -> OperatingSystemVersion { base.osVersion }
    func operatingSystemBuild() -> String { base.build }
    func isArm64() -> Bool { true }
    func isTranslated() -> Bool { false }
    func hardwareModel() -> String { "" }
    func cpuBrand() -> String { "" }
    func physicalMemoryBytes() -> UInt64 { base.memory }
    func availableCapacityBytes(forVolumeContaining url: URL) -> Int64? { base.availableDisk }
    func canWriteApplicationSupport(at url: URL) -> Bool { true }
    func sessionUser() -> SessionUser { base.user }
    func canBindLoopback(port: UInt16) -> Bool { true }
    func networkState() async -> NetworkState {
        try? await hangUntilCancelled()
        return .online
    }
    func existingInstall(paths: InstallPaths) -> ExistingInstallFacts { .none }
    func fileVaultState() -> FileVaultState { .on }
    func signedExecutables() -> [SignedExecutableResult] { base.signatures }
}

/// A signature checker that blocks, to park the machine in PACKAGE_VERIFY. The verifier is
/// synchronous, so the block is a plain sleep that observes the task's cancellation flag.
final class HangingSignatureChecker: CodeSignatureChecking {
    func validate(executableAt url: URL) throws {
        while !Task.isCancelled {
            Thread.sleep(forTimeInterval: 0.02)
        }

        throw CancellationError()
    }
}

/// Everything a state-machine test needs, built around a temporary root.
@MainActor
struct InstallHarness {
    let root: URL
    let paths: InstallPaths
    let packageDirectory: URL
    let clock: ManualClock
    let checkpointStore: InMemoryInstallCheckpointStore
    let ownerCreator = ScriptedOwnerCreator()
    let aiApplier = ScriptedAiSelectionApplier()
    let serviceBooter = ScriptedServiceBooter()
    var dependencies: InstallDependencies

    /// The fixture pack's reference instant (`packaging/fixtures/README.md`): 2026-10-09T12:00:00+02:00.
    static let fixtureStart = ISO8601DateFormatter().date(from: "2026-10-09T12:00:00+02:00")!
    static let fixtureZone = TimeZone(secondsFromGMT: 2 * 3600)!

    init(name: String = #function, clockStep: TimeInterval = 1.5, checkpoint: InstallCheckpoint? = nil) throws {
        root = FileManager.default.temporaryDirectory.appending(path: "lxai-tests-\(UUID().uuidString)")
        try FileManager.default.createDirectory(at: root, withIntermediateDirectories: true)
        paths = InstallPaths.rooted(at: root)
        packageDirectory = try SimulatedRuntimePackage.write(under: root.appending(path: "bundle-runtime"))
        clock = ManualClock(start: Self.fixtureStart, step: clockStep)
        checkpointStore = InMemoryInstallCheckpointStore(checkpoint: checkpoint)

        dependencies = InstallDependencies(
            paths: paths,
            productVersion: "0.1.0",
            language: .en,
            bundledRuntimeDirectory: packageDirectory,
            preflightProbes: StubPreflightProbes(),
            packageVerifier: PackageVerifier(signatureChecker: AcceptAnySignatureChecker()),
            runtimeInstaller: RuntimeInstaller(backupExclusion: RecordingBackupExclusion()),
            ownerCreator: ownerCreator,
            aiSelectionApplier: aiApplier,
            localAiRuntimeDetector: StaticLocalAiRuntimeDetector(runtimes: [.ollama]),
            serviceBooter: serviceBooter,
            healthProbe: ScriptedHealthProbe(),
            healthPolicy: HealthPollingPolicy(maxAttempts: 5, interval: .zero),
            checkpointStore: checkpointStore,
            clock: clock,
            timeZone: Self.fixtureZone,
            eventLogURL: { _ in nil }
        )
    }

    func makeMachine() -> InstallStateMachine {
        InstallStateMachine(dependencies: dependencies)
    }

    func tearDown() {
        // An installed runtime is read-only (chmod -R a-w); restore write bits before removing.
        try? RuntimeInstaller.makeWritable(root)
        try? FileManager.default.removeItem(at: root)
    }

    static let owner = OwnerDraft(name: "Test Owner", email: "owner@example.se")

    /// Welcome → … → READY with `skip` on the AI screen.
    func runHappyPath(_ machine: InstallStateMachine) async {
        machine.start()
        await machine.proceedFromWelcome()
        await machine.submitOwner(Self.owner)
        await machine.submitAiSelection(AiSelectionSubmission(selection: .skip))
    }
}

enum TestFiles {
    /// Walks up from this file to the repository root (the directory containing `packaging/`).
    static var repositoryRoot: URL {
        var url = URL(fileURLWithPath: #filePath)

        while url.pathComponents.count > 1 {
            url = url.deletingLastPathComponent()

            if FileManager.default.fileExists(atPath: url.appending(path: "packaging/manifest/install-timer/install-timer.mjs").path) {
                return url
            }
        }

        return url
    }

    static func which(_ tool: String) -> String? {
        let candidates = ["/opt/homebrew/bin", "/usr/local/bin", "/usr/bin"].map { "\($0)/\(tool)" }

        return candidates.first { FileManager.default.isExecutableFile(atPath: $0) }
    }

    @discardableResult
    static func run(_ executable: String, _ arguments: [String], input: Data? = nil) throws -> (status: Int32, stdout: String, stderr: String) {
        let process = Process()
        process.executableURL = URL(fileURLWithPath: executable)
        process.arguments = arguments

        let out = Pipe()
        let err = Pipe()
        process.standardOutput = out
        process.standardError = err

        if let input {
            let inPipe = Pipe()
            process.standardInput = inPipe
            try process.run()
            inPipe.fileHandleForWriting.write(input)
            try inPipe.fileHandleForWriting.close()
        } else {
            process.standardInput = FileHandle.nullDevice
            try process.run()
        }

        let stdout = String(decoding: out.fileHandleForReading.readDataToEndOfFile(), as: UTF8.self)
        let stderr = String(decoding: err.fileHandleForReading.readDataToEndOfFile(), as: UTF8.self)
        process.waitUntilExit()

        return (process.terminationStatus, stdout, stderr)
    }
}
