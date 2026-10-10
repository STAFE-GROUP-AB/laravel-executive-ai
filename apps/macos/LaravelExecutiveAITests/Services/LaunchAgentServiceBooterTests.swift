// BOOT_SERVICES live path against the recording registrar and a scripted control channel.
// Nothing here touches launchd.

import Foundation
import XCTest
@testable import LaravelExecutiveAI

final class LaunchAgentServiceBooterTests: XCTestCase {
    private let runtime = InstalledRuntime(version: "0.1.0", directory: URL(fileURLWithPath: "/tmp/lxai-runtime"))

    private func booter(registrar: RecordingRegistrar, control: ScriptedSupervisorControl, pending: PendingSecretHandoff = PendingSecretHandoff()) -> LaunchAgentServiceBooter {
        var waiting = LaunchAgentServiceBooter.Waiting()
        waiting.attempts = 3
        waiting.interval = .milliseconds(1)

        return LaunchAgentServiceBooter(registrar: registrar, control: control, pending: pending, waiting: waiting)
    }

    func testRegistersThenWaitsForTheSupervisorAndStarts() async throws {
        let registrar = RecordingRegistrar(statuses: [.notRegistered, .enabled])
        let control = ScriptedSupervisorControl(services: [.failure(.unreachable("not yet")), .success([])])

        let outcome = try await booter(registrar: registrar, control: control).boot(runtime: runtime, port: 8787)

        XCTAssertEqual(outcome, .started)
        XCTAssertEqual(registrar.calls, [.status, .register, .status])
        XCTAssertEqual(control.calls, [.services, .services], "polled until the Supervisor answered")
    }

    func testAlreadyEnabledAgentIsNotRegisteredAgain() async throws {
        let registrar = RecordingRegistrar(statuses: [.enabled])
        let control = ScriptedSupervisorControl()

        _ = try await booter(registrar: registrar, control: control).boot(runtime: runtime, port: 8787)

        XCTAssertEqual(registrar.calls, [.status, .status])
    }

    func testRequiresApprovalIsSurfacedWithoutWaiting() async throws {
        let registrar = RecordingRegistrar(statuses: [.notRegistered, .requiresApproval])
        let control = ScriptedSupervisorControl()

        let outcome = try await booter(registrar: registrar, control: control).boot(runtime: runtime, port: 8787)

        XCTAssertEqual(outcome, .requiresLoginItemApproval)
        XCTAssertEqual(control.calls, [], "no control call while the login item awaits approval")

        booter(registrar: registrar, control: control).openLoginItemsSettings()
        XCTAssertEqual(registrar.calls.last, .openLoginItemsSettings)
    }

    func testRegistrationFailureIsAnActionableError() async {
        let registrar = RecordingRegistrar(statuses: [.notRegistered], registerError: .registrationFailed("SMAppServiceErrorDomain 1"))

        do {
            _ = try await booter(registrar: registrar, control: ScriptedSupervisorControl()).boot(runtime: runtime, port: 8787)
            XCTFail("expected failure")
        } catch let error as ServiceBootError {
            XCTAssertEqual(error, .failed("The Supervisor could not be registered with launchd (SMAppServiceErrorDomain 1)."))
        } catch {
            XCTFail("unexpected \(error)")
        }
    }

    func testSupervisorNotAnsweringFails() async {
        let registrar = RecordingRegistrar(statuses: [.enabled])
        let control = ScriptedSupervisorControl(services: [.failure(.unreachable("down"))])

        do {
            _ = try await booter(registrar: registrar, control: control).boot(runtime: runtime, port: 8787)
            XCTFail("expected failure")
        } catch let error as ServiceBootError {
            XCTAssertEqual(error, .failed("The Supervisor was registered but did not answer on its control channel."))
            XCTAssertEqual(control.calls.count, 3)
        } catch {
            XCTFail("unexpected \(error)")
        }
    }

    func testPendingApiKeyIsHandedToTheSupervisorAndCleared() async throws {
        let pending = PendingSecretHandoff()
        let applier = SupervisorAiSelectionApplier(pending: pending)

        try await applier.apply(AiSelectionSubmission(selection: .skip))
        XCTAssertTrue(pending.isEmpty)

        try await applier.apply(AiSelectionSubmission(selection: .ownApiKey(.openAI), apiKey: TransientSecret("sk-test-not-a-real-key-0123456789")))
        XCTAssertFalse(pending.isEmpty)

        let control = ScriptedSupervisorControl()
        let outcome = try await booter(registrar: RecordingRegistrar(), control: control, pending: pending).boot(runtime: runtime, port: 8787)

        XCTAssertEqual(outcome, .started)
        XCTAssertEqual(control.calls, [.services, .storeProviderApiKey("openai")])
        XCTAssertEqual(control.storedKeys, ["sk-test-not-a-real-key-0123456789"])
        XCTAssertTrue(pending.isEmpty, "handed off once, then gone")
    }

    func testFailedHandOffKeepsTheKeyForRetry() async throws {
        let pending = PendingSecretHandoff()
        pending.set(provider: .anthropic, key: TransientSecret("sk-ant-test-not-a-real-key-0123456789")!)
        let control = ScriptedSupervisorControl(storeError: .secrets("keychain"))

        do {
            _ = try await booter(registrar: RecordingRegistrar(), control: control, pending: pending).boot(runtime: runtime, port: 8787)
            XCTFail("expected failure")
        } catch let error as ServiceBootError {
            XCTAssertEqual(error, .failed("The Supervisor could not store the AI provider key."))
        }

        XCTAssertFalse(pending.isEmpty, "a Retry can hand it off again")
    }

    func testOwnKeyWithoutAKeyIsRefused() async {
        let applier = SupervisorAiSelectionApplier(pending: PendingSecretHandoff())

        do {
            try await applier.apply(AiSelectionSubmission(selection: .ownApiKey(.mistral)))
            XCTFail("expected failure")
        } catch let error as AiSelectionError {
            XCTAssertEqual(error, .apiKeyMissing)
        } catch {
            XCTFail("unexpected \(error)")
        }
    }

    // The uninstall order itself (stop → sweep through the Supervisor → unregister → remove →
    // trash) is `Install/UninstallerTests`; here only the panel's port is checked.
    func testPanelServiceControlGoesThroughTheSupervisor() async throws {
        let control = ScriptedSupervisorControl()
        let cacheDirectory = FileManager.default.temporaryDirectory.appending(path: "lxai-cache-\(UUID().uuidString)")
        defer { try? FileManager.default.removeItem(at: cacheDirectory) }
        let cache = BriefCache(directory: cacheDirectory)
        try cache.store(document: .object(["id": .string("x")]), etag: "\"x:1\"")
        let uninstaller = ScriptedUninstaller()

        let serviceControl = SupervisorServiceControl(control: control, cache: cache, uninstaller: uninstaller)

        try await serviceControl.restartServices()
        try await serviceControl.stopServices()
        _ = try await serviceControl.resetPairing()

        XCTAssertEqual(control.calls, [.stopAll, .startAll, .stopAll, .resetPairing, .pair(.menubar)])
        XCTAssertNil(cache.load(), "reset security pairing clears the brief cache (ipc-contract §6.2)")

        let kept = try await serviceControl.uninstall(keepingData: true)
        let deleted = try await serviceControl.uninstall(keepingData: false)

        XCTAssertEqual(uninstaller.choices, [.keep, .delete], "the person's data choice reaches the uninstaller unchanged")
        XCTAssertEqual(kept.dataChoice, .keep)
        XCTAssertEqual(deleted.dataChoice, .delete)
        XCTAssertEqual(control.calls.count, 5, "uninstall goes through the Uninstaller, which owns the Supervisor calls; the panel port adds none of its own")
    }

    func testPanelUninstallFailureCarriesTheRetrySafeReasonAndNoPath() async throws {
        let serviceControl = SupervisorServiceControl(
            control: ScriptedSupervisorControl(),
            cache: BriefCache(directory: FileManager.default.temporaryDirectory.appending(path: "lxai-cache-\(UUID().uuidString)")),
            uninstaller: ScriptedUninstaller(failingWith: .supervisorUnreachable("timeout"))
        )

        do {
            _ = try await serviceControl.uninstall(keepingData: true)
            XCTFail("expected failure")
        } catch let error as ServiceControlError {
            XCTAssertEqual(error, .uninstallFailed(UninstallError.supervisorUnreachable("timeout").userFacingReason))

            if case .uninstallFailed(let reason) = error {
                XCTAssertFalse(reason.contains("/"), "no path reaches the Settings window")
                XCTAssertTrue(reason.contains("nothing has been removed yet"))
            }
        }
    }
}
