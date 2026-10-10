// The installer's health probe over the IPC client (installer.md §6, S1-08 row): ready, not
// ready and unreachable become `HealthReadiness` / `HealthProbeError` exactly as the state machine
// expects, `/health/ready` is signed with the menubar grant, a tampered answer is unreachable,
// and `HEALTH_CHECK` → `READY` is reachable through the real probe against the stubbed core.

import Foundation
import Synchronization
import XCTest
@testable import LaravelExecutiveAI

final class IpcHealthProbeTests: XCTestCase {
    override func setUp() {
        super.setUp()
        IpcStubURLProtocol.reset()
    }

    override func tearDown() {
        IpcStubURLProtocol.reset()
        super.tearDown()
    }

    private func makeProbe(port: Int? = IpcTestSupport.port, session: IpcSession = IpcTestSupport.session()) -> IpcHealthProbe {
        IpcHealthProbe(port: { port }, session: session) { port, session in
            IpcTestSupport.client(port: port, session: session)
        }
    }

    private func healthDocument(_ fixture: HealthFixture) throws -> Data {
        Data(try JCS.canonicalize(try IpcTestSupport.healthFixtureData(fixture)).utf8)
    }

    func testReadyAnswersBecomeReadiness() async throws {
        let body = try healthDocument(.coreReadyNoAI)
        IpcStubURLProtocol.install { request, _ in IpcTestSupport.signedReply(for: request, status: 200, body: body) }

        let readiness = try await makeProbe().readiness()

        XCTAssertEqual(readiness, HealthReadiness(ready: true, failingChecks: [], aiState: "not_configured"))
        XCTAssertTrue(readiness.aiSetupRequired)

        let recorded = try XCTUnwrap(IpcStubURLProtocol.requests.first)
        XCTAssertEqual(recorded.request.url?.absoluteString, "http://127.0.0.1:8787/health/ready")
        XCTAssertEqual(recorded.request.httpMethod, "GET")
        XCTAssertTrue(recorded.header("Authorization")?.hasPrefix("Bearer lxai1.menubar.") == true)
        XCTAssertEqual(recorded.header("X-Lxai-Ipc-Version"), "1")
        XCTAssertNotNil(recorded.header("X-Lxai-Signature"))
        IpcHeaderRules.assertClean(recorded.request)
    }

    func testANotReady503CarriesTheFailingCheckNamesOnly() async throws {
        let body = try healthDocument(.notReadySchedulerFailing)
        IpcStubURLProtocol.install { request, _ in IpcTestSupport.signedReply(for: request, status: 503, body: body) }

        let readiness = try await makeProbe().readiness()

        XCTAssertEqual(readiness, HealthReadiness(ready: false, failingChecks: ["scheduler_selftest"], aiState: "configured_untested"))
        XCTAssertFalse(String(describing: readiness).contains("launchd"), "check detail text never crosses into the installer")
    }

    func testAnAiReadyAnswerIsReadyWithoutSetupRequired() async throws {
        let body = try healthDocument(.aiReady)
        IpcStubURLProtocol.install { request, _ in IpcTestSupport.signedReply(for: request, status: 200, body: body) }

        let readiness = try await makeProbe().readiness()

        XCTAssertEqual(readiness.aiState, "ready")
        XCTAssertFalse(readiness.aiSetupRequired)
    }

    func testNothingListeningIsUnreachable() async throws {
        IpcStubURLProtocol.install { _, _ in .failure(.cannotConnectToHost) }

        await XCTAssertThrowsErrorAsync(try await makeProbe().readiness()) { error in
            XCTAssertEqual(error as? HealthProbeError, .unreachable("core unreachable: no answer on loopback"))
        }
    }

    func testATamperedAnswerIsUnreachableNotReady() async throws {
        let honest = try healthDocument(.notReadySchedulerFailing)
        let forged = try healthDocument(.aiReady)
        IpcStubURLProtocol.install { request, _ in
            guard case .response(let status, let headers, _) = IpcTestSupport.signedReply(for: request, status: 503, body: honest) else {
                return .failure(.unknown)
            }

            return .response(status: status, headers: headers, body: forged)
        }

        await XCTAssertThrowsErrorAsync(try await makeProbe().readiness()) { error in
            XCTAssertEqual(error as? HealthProbeError, .unreachable("core unreachable: the response signature did not verify"))
        }
    }

    func testAnUnsignedReadyAnswerIsUnreachable() async throws {
        let body = try healthDocument(.aiReady)
        IpcStubURLProtocol.install { _, _ in IpcTestSupport.unsignedReply(status: 200, body: body) }

        await XCTAssertThrowsErrorAsync(try await makeProbe().readiness()) { error in
            XCTAssertEqual(error as? HealthProbeError, .unreachable("core unreachable: the response carried no signature"))
        }
    }

    func testASignedBodyThatIsNotAHealthDocumentIsInvalid() async throws {
        let body = Data("{\"ok\":true}".utf8)
        IpcStubURLProtocol.install { request, _ in IpcTestSupport.signedReply(for: request, status: 200, body: body) }

        await XCTAssertThrowsErrorAsync(try await makeProbe().readiness()) { error in
            XCTAssertEqual(error as? HealthProbeError, .invalidResponse)
        }
    }

    func testNoRecordedPortIsUnreachableWithoutARequest() async throws {
        await XCTAssertThrowsErrorAsync(try await makeProbe(port: nil).readiness()) { error in
            XCTAssertEqual(error as? HealthProbeError, .unreachable("no loopback port has been recorded"))
        }

        XCTAssertEqual(IpcStubURLProtocol.requests.count, 0)
        let alive = await makeProbe(port: nil).live()
        XCTAssertFalse(alive)
    }

    func testAPersistentlyInvalidSessionIsUnreachable() async throws {
        let invalid = try IpcTestSupport.errorBody(code: "session_invalid")
        IpcStubURLProtocol.install { _, _ in IpcTestSupport.unsignedReply(status: 401, body: invalid) }

        await XCTAssertThrowsErrorAsync(try await makeProbe().readiness()) { error in
            XCTAssertEqual(error as? HealthProbeError, .unreachable("session invalid"))
        }
    }

    func testTheProbeNeverUsesTheUnavailableFailure() async throws {
        IpcStubURLProtocol.install { _, _ in .failure(.timedOut) }

        await XCTAssertThrowsErrorAsync(try await makeProbe().readiness()) { error in
            if case .unavailable = error as? HealthProbeError {
                XCTFail("`unavailable` means no probe is wired; the live probe is wired")
            }
        }
    }

    func testThePortIsReadFromTheCheckpointWhenTheProbeRuns() async throws {
        let store = InMemoryInstallCheckpointStore()
        let probe = IpcHealthProbe(checkpointStore: store, session: IpcTestSupport.session(grant: IpcTestSupport.grant(port: 8791))) { port, session in
            IpcTestSupport.client(port: port, session: session)
        }
        let body = try healthDocument(.coreReadyNoAI)
        IpcStubURLProtocol.install { request, _ in IpcTestSupport.signedReply(for: request, status: 200, body: body, port: 8791) }

        await XCTAssertThrowsErrorAsync(try await probe.readiness())

        var checkpoint = InstallCheckpoint(updatedAt: Date())
        checkpoint.preflight = InstallCheckpoint.PreflightSummary(port: 8791, language: .en, existingInstall: .fresh, warnings: [], fileVaultEnabled: nil)
        try store.save(checkpoint)

        let readiness = try await probe.readiness()

        XCTAssertTrue(readiness.ready)
        XCTAssertEqual(IpcStubURLProtocol.requests.last?.request.url?.port, 8791)
    }

    @MainActor
    func testTheInstallerReachesReadyThroughTheLiveProbe() async throws {
        let harness = try InstallHarness()
        defer { harness.tearDown() }
        var dependencies = harness.dependencies
        dependencies.healthProbe = IpcHealthProbe(checkpointStore: harness.checkpointStore, session: IpcTestSupport.session()) { port, session in
            IpcTestSupport.client(port: port, session: session)
        }
        dependencies.healthPolicy = HealthPollingPolicy(maxAttempts: 3, interval: .zero)

        let notReady = try healthDocument(.notReadySchedulerFailing)
        let ready = try healthDocument(.coreReadyNoAI)
        let calls = Mutex<Int>(0)
        IpcStubURLProtocol.install { request, _ in
            let number = calls.withLock { value -> Int in
                value += 1
                return value
            }

            return number == 1
                ? IpcTestSupport.signedReply(for: request, status: 503, body: notReady, port: request.url?.port ?? 0)
                : IpcTestSupport.signedReply(for: request, status: 200, body: ready, port: request.url?.port ?? 0)
        }

        let machine = InstallStateMachine(dependencies: dependencies)
        await harness.runHappyPath(machine)

        XCTAssertEqual(machine.state, .ready)
        XCTAssertEqual(machine.lastHealth, HealthReadiness(ready: true, failingChecks: [], aiState: "not_configured"))
        XCTAssertGreaterThanOrEqual(IpcStubURLProtocol.requests.count, 2)
        XCTAssertTrue(IpcStubURLProtocol.requests.allSatisfy { $0.request.url?.path == "/health/ready" })
    }
}

func XCTAssertThrowsErrorAsync<T>(_ expression: @autoclosure () async throws -> T, _ message: String = "", file: StaticString = #filePath, line: UInt = #line, _ handler: (any Error) -> Void = { _ in }) async {
    do {
        _ = try await expression()
        XCTFail("expected an error \(message)", file: file, line: line)
    } catch {
        handler(error)
    }
}
