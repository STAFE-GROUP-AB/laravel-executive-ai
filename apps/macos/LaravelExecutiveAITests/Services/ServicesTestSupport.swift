// Shared helpers for the S1-07 supervision tests. Real processes are `/bin/sh -c` scripts;
// launchd, the Keychain and XPC are always the recording doubles. Nothing here registers an
// agent, writes the real Keychain or opens a Mach service.

import Foundation
import XCTest
@testable import LaravelExecutiveAI

enum ServicesTestSupport {
    static let shell = URL(fileURLWithPath: "/bin/sh")

    /// A spec that runs `script` through `/bin/sh -c` with a minimal, secret-free environment.
    static func shellSpec(_ name: ServiceName, _ script: String, environment: [String: String] = ["PATH": "/usr/bin:/bin"]) -> ServiceSpec {
        ServiceSpec(name: name, executable: shell, arguments: ["-c", script], workingDirectory: URL(fileURLWithPath: "/"), environment: environment)
    }

    /// A spec whose executable does not exist, so spawning fails immediately.
    static func missingSpec(_ name: ServiceName) -> ServiceSpec {
        ServiceSpec(name: name, executable: URL(fileURLWithPath: "/nonexistent/lxai-missing-binary"), arguments: [], workingDirectory: URL(fileURLWithPath: "/"), environment: [:])
    }

    /// Fast supervision: millisecond backoff, no grace, synthetic clock not needed.
    static func fastConfiguration(policy: RestartPolicy = RestartPolicy(delays: [.milliseconds(20), .milliseconds(40)], maxRestarts: 3, window: .seconds(60)), log: (@Sendable (ServiceName) -> any LogSink)? = nil) -> ServiceSupervisor.Configuration {
        var configuration = ServiceSupervisor.Configuration()
        configuration.policy = policy
        configuration.gracePeriod = .milliseconds(200)
        configuration.liveFailuresBeforeRestart = 3
        configuration.readyFailuresBeforeRestart = 3

        if let log {
            configuration.makeLog = log
        }

        return configuration
    }

    /// Polls until `condition` is true or `timeout` passes.
    static func waitUntil(timeout: Duration = .seconds(10), _ condition: @escaping @Sendable () async -> Bool) async -> Bool {
        let deadline = ContinuousClock.now + timeout

        while ContinuousClock.now < deadline {
            if await condition() {
                return true
            }

            try? await Task.sleep(for: .milliseconds(25))
        }

        return await condition()
    }

    static func status(_ supervisor: ServiceSupervisor, _ name: ServiceName) async -> ServiceStatus? {
        await supervisor.statuses().first { $0.name == name }
    }

    /// A Unix socket path short enough for `sun_path` (103 bytes).
    static func shortSocketPath(_ label: String = "s") -> URL {
        let suffix = "lx-\(UUID().uuidString.prefix(8))-\(label).sock"
        let temporary = FileManager.default.temporaryDirectory.appending(path: suffix)

        if temporary.path.utf8.count <= 100 {
            return temporary
        }

        return URL(fileURLWithPath: "/private/tmp").appending(path: suffix)
    }

    /// Every synthetic secret value in tests/Security/fixtures/secrets-corpus.json.
    static func secretsCorpusValues() throws -> [String] {
        let url = TestFiles.repositoryRoot.appending(path: "tests/Security/fixtures/secrets-corpus.json")
        let data = try Data(contentsOf: url)
        let object = try XCTUnwrap(try JSONSerialization.jsonObject(with: data) as? [String: Any])
        var values: [String] = []

        for key in ["pattern_secrets", "keyed_secrets", "high_entropy_header_values"] {
            for entry in object[key] as? [[String: Any]] ?? [] {
                if let value = entry["value"] as? String {
                    values.append(value)
                }
            }
        }

        XCTAssertFalse(values.isEmpty, "corpus is empty")

        return values
    }
}

/// A log sink that keeps everything in memory.
final class CapturingLogSink: LogSink, @unchecked Sendable {
    private let lock = NSLock()
    private var buffer = Data()

    func append(_ data: Data) {
        lock.withLock { buffer.append(data) }
    }

    var text: String {
        lock.withLock { String(decoding: buffer, as: UTF8.self) }
    }
}

/// A scripted health probe: `live` answers from a queue (last repeats); `ready` likewise.
final class ScriptedHealthProbing: HealthProbing, @unchecked Sendable {
    private let lock = NSLock()
    private var liveAnswers: [Bool]
    private var readyAnswers: [Result<ReadyProbeResult, HealthProbeFailure>]
    private(set) var liveCalls = 0
    private(set) var readyCalls = 0

    init(live: [Bool] = [true], ready: [Result<ReadyProbeResult, HealthProbeFailure>] = [.success(ReadyProbeResult(ready: true, checks: ["queue_worker": .ok, "scheduler_selftest": .ok]))]) {
        precondition(!live.isEmpty && !ready.isEmpty)
        liveAnswers = live
        readyAnswers = ready
    }

    func live() async -> Bool {
        lock.withLock {
            liveCalls += 1
            return liveAnswers.count > 1 ? liveAnswers.removeFirst() : liveAnswers[0]
        }
    }

    func ready() async throws -> ReadyProbeResult {
        let next = lock.withLock {
            readyCalls += 1
            return readyAnswers.count > 1 ? readyAnswers.removeFirst() : readyAnswers[0]
        }

        return try next.get()
    }
}

/// Records every control call; answers `services()` from a script. With `secrets:` set,
/// `removeAllSecrets()` sweeps that in-memory inventory the way the real service does, so an
/// uninstall test can assert on the Keychain double; `stopAllError`/`removeSecretsError` script
/// an unreachable or refusing Supervisor.
final class ScriptedSupervisorControl: SupervisorControlling, @unchecked Sendable {
    enum Call: Equatable, Sendable {
        case pair(IpcClient)
        case resetPairing
        case services
        case restart(ServiceName)
        case stopAll
        case startAll
        case elevate(String)
        case storeProviderApiKey(String)
        case removeAllSecrets
    }

    private let lock = NSLock()
    private var servicesAnswers: [Result<[ServiceStatus], SupervisorControlError>]
    private let storeError: SupervisorControlError?
    private let stopAllError: SupervisorControlError?
    private let removeSecretsError: SupervisorControlError?
    private let secrets: SupervisorSecrets?
    private(set) var calls: [Call] = []
    private(set) var storedKeys: [String] = []

    init(
        services: [Result<[ServiceStatus], SupervisorControlError>] = [.success([])],
        storeError: SupervisorControlError? = nil,
        stopAllError: SupervisorControlError? = nil,
        removeSecretsError: SupervisorControlError? = nil,
        secrets: SupervisorSecrets? = nil
    ) {
        precondition(!services.isEmpty)
        servicesAnswers = services
        self.storeError = storeError
        self.stopAllError = stopAllError
        self.removeSecretsError = removeSecretsError
        self.secrets = secrets
    }

    private func record(_ call: Call) {
        lock.withLock { calls.append(call) }
    }

    func pair(client: IpcClient) async throws -> PairingGrant {
        record(.pair(client))
        return PairingGrant.mint(key: IpcKey(Data(repeating: 7, count: 32))!, client: client, port: 8787, now: Date())
    }

    func resetPairing() async throws -> PairingGrant {
        record(.resetPairing)
        return try await pair(client: .menubar)
    }

    func services() async throws -> [ServiceStatus] {
        record(.services)
        let next = lock.withLock { servicesAnswers.count > 1 ? servicesAnswers.removeFirst() : servicesAnswers[0] }
        return try next.get()
    }

    func restart(service: ServiceName) async throws { record(.restart(service)) }
    func startAll() async throws { record(.startAll) }

    func stopAll() async throws {
        record(.stopAll)

        if let stopAllError {
            throw stopAllError
        }
    }

    func removeAllSecrets() async throws -> [KeychainItemRef] {
        record(.removeAllSecrets)

        if let removeSecretsError {
            throw removeSecretsError
        }

        guard let secrets else {
            return []
        }

        return try secrets.removeAll()
    }

    func elevate(sessionId: String, operation: String, nonce: String) async throws -> Elevation {
        record(.elevate(operation))
        return Elevation(value: "lxai1e.\(operation).\(nonce).0.00000000000000000000000000000000", operation: operation, nonce: nonce, expiresAt: Date())
    }

    func storeProviderApiKey(provider: String, apiKey: TransientSecret) async throws {
        record(.storeProviderApiKey(provider))

        if let storeError {
            throw storeError
        }

        lock.withLock { storedKeys.append(apiKey.use { $0 }) }
    }
}

/// Records the data choice handed to `uninstall(choice:)` and answers from a script.
final class ScriptedUninstaller: Uninstalling, @unchecked Sendable {
    private let lock = NSLock()
    private let failure: UninstallError?
    private(set) var choices: [UninstallDataChoice] = []

    init(failingWith failure: UninstallError? = nil) {
        self.failure = failure
    }

    func uninstall(choice: UninstallDataChoice) async throws -> UninstallReport {
        lock.withLock { choices.append(choice) }

        if let failure {
            throw failure
        }

        return UninstallReport(dataChoice: choice, performed: UninstallStep.allCases, resumedFrom: [], secretsSweep: .removed(count: 2), preservedData: nil, appBundleTrashed: true)
    }
}
