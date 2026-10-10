// The Supervisor's boot sequence (services.md §2) against a temporary install, the in-memory
// Keychain and recording doubles: verification gates every spawn, and failure is visible.

import Foundation
import XCTest
@testable import LaravelExecutiveAI

final class SupervisorRuntimeTests: XCTestCase {
    private var root: URL!
    private var paths: InstallPaths!

    override func setUpWithError() throws {
        root = FileManager.default.temporaryDirectory.appending(path: "lxai-sup-\(UUID().uuidString)")
        try FileManager.default.createDirectory(at: root, withIntermediateDirectories: true)
        paths = InstallPaths.rooted(at: root)
    }

    override func tearDown() {
        try? RuntimeInstaller.makeWritable(root)
        try? FileManager.default.removeItem(at: root)
    }

    /// A runtime directory the verifier accepts, `current` pointing at it, and a checkpoint. The
    /// simulated `frankenphp` is a shell script; the mode bit is not part of the manifest, so
    /// making it executable keeps the package verifiable.
    private func installRuntime(port: Int = 8787) throws {
        let directory = try SimulatedRuntimePackage.write(under: paths.runtimeDirectory(version: "0.1.0"))
        try FileManager.default.setAttributes([.posixPermissions: 0o755], ofItemAtPath: directory.appending(path: "frankenphp").path)
        try FileManager.default.createDirectory(at: paths.runtimeRoot, withIntermediateDirectories: true)
        try FileManager.default.createSymbolicLink(atPath: paths.currentRuntimeLink.path, withDestinationPath: "0.1.0")
        try FileManager.default.createDirectory(at: paths.installDirectory, withIntermediateDirectories: true)
        try Data("{\"schema_version\":1,\"preflight\":{\"port\":\(port)}}".utf8).write(to: paths.checkpointURL)
    }

    private func configuration(keychain: InMemoryKeychainStore, signature: any CodeSignatureChecking) -> SupervisorConfiguration {
        var supervision = ServicesTestSupport.fastConfiguration()
        supervision.policy = RestartPolicy(delays: [.milliseconds(5)], maxRestarts: 1, window: .seconds(60))

        return SupervisorConfiguration(
            identity: SupervisorIdentity(bundlePrefix: "se.stafe.executive-ai", teamIdentifier: nil),
            paths: paths,
            keychain: keychain,
            verifier: PackageVerifier(signatureChecker: signature),
            peerVerifier: RecordingPeerVerifier(verdict: .rejected("test")),
            authenticator: ScriptedDeviceOwnerAuthenticator(succeeds: true),
            allowUnsignedXPCPeers: false,
            supervision: supervision,
            makeProbe: { _, _ in ScriptedHealthProbing() },
            liveInterval: .seconds(60),
            readyInterval: .seconds(60),
            secretsSocketOverride: ServicesTestSupport.shortSocketPath("rt")
        )
    }

    func testBootWithoutARuntimeOrPortIsAFatalBootError() async throws {
        let keychain = InMemoryKeychainStore()
        let runtime = SupervisorRuntime(configuration: configuration(keychain: keychain, signature: AcceptAnySignatureChecker()))

        do {
            try await runtime.boot()
            XCTFail("expected noInstalledRuntime")
        } catch let error as SupervisorBootError {
            XCTAssertEqual(error, .noInstalledRuntime)
        }

        _ = try SimulatedRuntimePackage.write(under: paths.runtimeDirectory(version: "0.1.0"))
        try FileManager.default.createSymbolicLink(atPath: paths.currentRuntimeLink.path, withDestinationPath: "0.1.0")

        do {
            try await runtime.boot()
            XCTFail("expected noRecordedPort")
        } catch let error as SupervisorBootError {
            XCTAssertEqual(error, .noRecordedPort)
        }

        XCTAssertTrue(keychain.snapshot.isEmpty, "no secret is generated before the install is complete")
        await runtime.shutdown()
    }

    func testVerificationFailureBlocksEverySpawnAndIsVisible() async throws {
        try installRuntime()
        let keychain = InMemoryKeychainStore()
        let configuration = configuration(keychain: keychain, signature: FailClosedSignatureChecker(reason: "no Team ID"))
        let runtime = SupervisorRuntime(configuration: configuration)

        try await runtime.boot()

        let statuses = await runtime.statuses()
        XCTAssertEqual(statuses.map(\.name), [.core, .worker, .scheduler])

        for status in statuses {
            XCTAssertEqual(status.state, .failed)
            XCTAssertNil(status.pid)
            XCTAssertEqual(status.detail, "The installed runtime failed verification (No signing policy is available (no Team ID).).")
        }

        // Secrets exist before verification so the broker can serve them, and K is valid.
        XCTAssertNotNil(keychain.snapshot[SupervisorSecrets.appKeyRef])
        XCTAssertNotNil(keychain.snapshot[SupervisorSecrets.ipcKeyRef])

        // The broker socket and the control surface are up even though nothing is spawned, so
        // the GUI can show the failure and a person can repair and "Start services".
        let socket = try XCTUnwrap(configuration.secretsSocketOverride)
        XCTAssertTrue(FileManager.default.fileExists(atPath: socket.path), "broker socket missing at \(socket.path)")

        await runtime.shutdown()
        XCTAssertFalse(FileManager.default.fileExists(atPath: socket.path), "shutdown unlinks the socket")
    }

    func testVerifiedRuntimeSpawnsTheThreeChildrenWithTheContractEnvironment() async throws {
        try installRuntime(port: 8792)
        let keychain = InMemoryKeychainStore()
        let runtime = SupervisorRuntime(configuration: configuration(keychain: keychain, signature: AcceptAnySignatureChecker()))

        try await runtime.boot()

        // The simulated `frankenphp` is a shell script that exits at once, so the children run
        // through the bounded restart path; what matters here is that spawning happened.
        let moved = await ServicesTestSupport.waitUntil {
            let statuses = await runtime.statuses()
            return statuses.allSatisfy { $0.lastExitReason != nil || $0.pid != nil }
        }
        XCTAssertTrue(moved, "every child was spawned")

        // Output reaches the log asynchronously after the exit is observed; wait for the file.
        let coreLog = paths.logs.appending(path: "core.log")
        let logged = await ServicesTestSupport.waitUntil { FileManager.default.fileExists(atPath: coreLog.path) }
        XCTAssertTrue(logged, "stdout/stderr land in LEA_LOG_DIR as <service>.log")
        let text = try String(contentsOf: coreLog, encoding: .utf8)
        XCTAssertTrue(text.contains("simulated runtime"), text)

        let appKey = try JSONDecoder().decode(AppKeyEnvelope.self, from: XCTUnwrap(keychain.snapshot[SupervisorSecrets.appKeyRef]).data)
        XCTAssertTrue(appKey.current.hasPrefix("base64:"))

        await runtime.shutdown()
        let stopped = await runtime.statuses()
        XCTAssertTrue(stopped.allSatisfy { $0.state == .stopped || $0.state == .failed })
    }

    func testBootPlanReadsPortAndRuntime() throws {
        XCTAssertNil(SupervisorBootPlan.installedRuntime(in: paths))
        XCTAssertNil(SupervisorBootPlan.port(from: paths.checkpointURL))

        try installRuntime(port: 8799)

        XCTAssertEqual(SupervisorBootPlan.installedRuntime(in: paths), InstalledRuntime(version: "0.1.0", directory: paths.runtimeDirectory(version: "0.1.0")))
        XCTAssertEqual(SupervisorBootPlan.port(from: paths.checkpointURL), 8799)
        XCTAssertEqual(SupervisorBootPlan.secretsSocket(in: paths), paths.applicationSupport.appending(path: "run").appending(path: "secrets.sock"))

        try Data("{\"preflight\":{\"port\":70000}}".utf8).write(to: paths.checkpointURL)
        XCTAssertNil(SupervisorBootPlan.port(from: paths.checkpointURL), "an impossible port is refused")
    }

    func testIdentityResolution() {
        let fromEnvironment = SupervisorIdentity.resolve(environment: ["LXAI_BUNDLE_PREFIX": "se.example.prefix"], executable: nil)
        XCTAssertEqual(fromEnvironment.bundlePrefix, "se.example.prefix")
        XCTAssertEqual(fromEnvironment.appBundleIdentifier, "se.example.prefix.app")
        XCTAssertEqual(fromEnvironment.machServiceName, "se.example.prefix.supervisor.control")
        XCTAssertEqual(fromEnvironment.plistName, "se.example.prefix.supervisor.plist")

        let fromBundle = SupervisorIdentity.resolve(environment: [:], executable: Bundle.main.bundleURL.appending(path: "Contents/MacOS/LaravelExecutiveAISupervisor"))
        XCTAssertEqual(fromBundle.appBundleIdentifier, Bundle.main.bundleIdentifier)

        let fallback = SupervisorIdentity.resolve(environment: [:], executable: URL(fileURLWithPath: "/nowhere/tool"))
        XCTAssertEqual(fallback.bundlePrefix, SupervisorIdentity.defaultBundlePrefix)

        // An unsigned test host has no Team ID: no access group, no requirement — fail closed.
        XCTAssertNil(fallback.teamIdentifier)
        XCTAssertNil(fallback.keychainAccessGroup)
        XCTAssertNil(fallback.codeSigningRequirement)

        // A placeholder team id (A12 is undecided); the expected group is assembled from its
        // parts so no credential-shaped literal sits in the source (gitleaks generic-api-key).
        let team = "TEAMID0000"
        let signed = SupervisorIdentity(bundlePrefix: "se.stafe.executive-ai", teamIdentifier: team)
        XCTAssertEqual(signed.keychainAccessGroup, [team, SecretService.prefix, "secrets"].joined(separator: "."))
        XCTAssertEqual(signed.codeSigningRequirement, SupervisorControlWire.codeSigningRequirement(teamIdentifier: team))
        XCTAssertTrue(signed.codeSigningRequirement?.hasPrefix("anchor apple generic and certificate leaf[subject.OU] = \"") == true)
    }
}
