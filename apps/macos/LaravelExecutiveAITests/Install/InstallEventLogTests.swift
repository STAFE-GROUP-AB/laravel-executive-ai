// The install-measurement §4 event log: exact line shape, RFC 3339 ms timestamps on one clock,
// prompt pairs without nesting, the committed e2e fixture, and the checkpoint file format.

import Foundation
import XCTest
@testable import LaravelExecutiveAI

@MainActor
final class InstallEventLogTests: XCTestCase {
    private var log: InstallEventLog!

    override func setUp() async throws {
        await MainActor.run {
            log = InstallEventLog(fileURL: nil, clock: ManualClock(start: InstallHarness.fixtureStart, step: 1.5), timeZone: InstallHarness.fixtureZone)
        }
    }

    /// The happy path as the state machine emits it, plus one skipped optional step.
    private func writeHappyPath() {
        log.enter(.welcome)
        log.humanInteractionStart()
        log.humanInteractionEnd()
        log.enter(.preflight)
        log.enter(.packageVerify)
        log.enter(.installCore)
        log.enter(.createOwner)
        log.humanInteractionStart()
        log.humanInteractionEnd()
        log.enter(.selectAI)
        log.humanInteractionStart()
        log.humanInteractionEnd()
        log.enter(.bootServices)
        log.enter(.healthCheck)
        log.enter(.ready)
        log.asyncStart(.connectEmail)
        log.asyncEnd(.connectEmail, outcome: .skipped)
    }

    func testLinesHaveExactlyTheContractShape() {
        writeHappyPath()

        XCTAssertEqual(log.lines.first, "{\"state\":\"WELCOME\",\"at\":\"2026-10-09T12:00:00.000+02:00\"}")
        XCTAssertEqual(log.lines[1], "{\"state\":\"human_interaction_start\",\"at\":\"2026-10-09T12:00:01.500+02:00\"}")
        XCTAssertEqual(log.lines[15], "{\"state\":\"CONNECT_EMAIL\",\"marker\":\"start\",\"at\":\"2026-10-09T12:00:22.500+02:00\"}")
        XCTAssertEqual(log.lines[16], "{\"state\":\"CONNECT_EMAIL\",\"marker\":\"end\",\"outcome\":\"skipped\",\"at\":\"2026-10-09T12:00:24.000+02:00\"}")
        XCTAssertEqual(log.enteredStates, InstallState.coreSequence)

        for line in log.lines {
            let object = try? JSONSerialization.jsonObject(with: Data(line.utf8)) as? [String: String]
            XCTAssertNotNil(object, line)
            XCTAssertTrue(Set(object?.keys.map { $0 } ?? []).isSubset(of: ["state", "at", "marker", "outcome"]), line)
        }
    }

    func testMatchesTheCommittedE2EFixture() throws {
        writeHappyPath()

        let fixture = TestFiles.repositoryRoot.appending(path: "packaging/fixtures/e2e/installer-events.ndjson")
        let committed = try String(contentsOf: fixture, encoding: .utf8)

        XCTAssertEqual(log.ndjson, committed, "regenerate the fixture from this test's output when the log format changes deliberately")
    }

    func testPromptsNeverNestAndEndWithoutStartIsIgnored() {
        log.humanInteractionEnd()
        log.humanInteractionStart()
        log.humanInteractionStart()
        XCTAssertTrue(log.isWaitingForPerson)
        log.humanInteractionEnd()
        log.humanInteractionEnd()
        XCTAssertFalse(log.isWaitingForPerson)

        XCTAssertEqual(log.events.map(\.stateName), ["human_interaction_start", "human_interaction_end"])
    }

    func testUTCIsWrittenAsZ() {
        XCTAssertEqual(InstallEventLog.rfc3339(Date(timeIntervalSince1970: 0.25), timeZone: TimeZone(secondsFromGMT: 0)!), "1970-01-01T00:00:00.250Z")
        XCTAssertEqual(InstallEventLog.rfc3339(InstallHarness.fixtureStart, timeZone: InstallHarness.fixtureZone), "2026-10-09T12:00:00.000+02:00")
    }

    func testAppendsToTheFileWithPrivatePermissions() throws {
        let directory = FileManager.default.temporaryDirectory.appending(path: "lxai-log-\(UUID().uuidString)")
        defer { try? FileManager.default.removeItem(at: directory) }
        let url = directory.appending(path: "nested/install-events.ndjson")

        let fileLog = InstallEventLog(fileURL: url, clock: ManualClock(start: InstallHarness.fixtureStart), timeZone: InstallHarness.fixtureZone)
        fileLog.enter(.welcome)
        fileLog.enter(.preflight)

        let contents = try String(contentsOf: url, encoding: .utf8)
        XCTAssertEqual(contents, fileLog.ndjson)
        XCTAssertEqual(contents.split(separator: "\n").count, 2)

        let permissions = try FileManager.default.attributesOfItem(atPath: url.path)[.posixPermissions] as? Int
        XCTAssertEqual(permissions, 0o600)
    }

    func testMonotonicClockNeverGoesBackwards() {
        let clock = MonotonicWallClock()
        var previous = clock.now()

        for _ in 0..<100 {
            let next = clock.now()
            XCTAssertGreaterThanOrEqual(next, previous)
            previous = next
        }

        XCTAssertLessThan(abs(previous.timeIntervalSinceNow), 5)
    }

    // MARK: - Checkpoint persistence

    func testCheckpointFileRoundTripsWithSnakeCaseKeysAndNoSecrets() throws {
        let directory = FileManager.default.temporaryDirectory.appending(path: "lxai-cp-\(UUID().uuidString)")
        defer { try? FileManager.default.removeItem(at: directory) }
        let store = FileInstallCheckpointStore(url: directory.appending(path: "install/checkpoint.json"))

        XCTAssertNil(try store.load())

        var checkpoint = InstallCheckpoint(updatedAt: InstallHarness.fixtureStart)
        checkpoint.lastCompletedState = .installCore
        checkpoint.preflight = InstallCheckpoint.PreflightSummary(port: 8788, language: .sv, existingInstall: .reinstallOrUpgrade, warnings: [.portCollision, .fileVault], fileVaultEnabled: false)
        checkpoint.runtime = InstallCheckpoint.RuntimeProgress(version: "0.1.0", stagingDirectoryName: nil, activated: true)
        checkpoint.ownerCreated = false
        checkpoint.aiSelection = .ownApiKey(.anthropic)
        try store.save(checkpoint)

        let json = try String(contentsOf: store.url, encoding: .utf8)
        for key in ["\"schema_version\"", "\"run_id\"", "\"last_completed_state\" : \"INSTALL_CORE\"", "\"existing_install\" : \"reinstall_or_upgrade\"", "\"filevault_enabled\" : false", "\"owner_created\" : false", "\"kind\" : \"own_api_key\"", "\"provider\" : \"anthropic\""] {
            XCTAssertTrue(json.contains(key), "missing \(key) in\n\(json)")
        }

        XCTAssertEqual(try store.load(), checkpoint)
        XCTAssertEqual(try FileManager.default.attributesOfItem(atPath: store.url.path)[.posixPermissions] as? Int, 0o600)

        try store.clear()
        XCTAssertNil(try store.load())
        try store.clear()
    }

    func testUnsupportedSchemaVersionIsRefused() throws {
        let directory = FileManager.default.temporaryDirectory.appending(path: "lxai-cp-\(UUID().uuidString)")
        defer { try? FileManager.default.removeItem(at: directory) }
        let store = FileInstallCheckpointStore(url: directory.appending(path: "checkpoint.json"))

        var checkpoint = InstallCheckpoint(updatedAt: InstallHarness.fixtureStart)
        checkpoint.schemaVersion = 99
        try store.save(checkpoint)

        XCTAssertThrowsError(try store.load()) { error in
            XCTAssertEqual(error as? InstallCheckpointError, .unsupportedSchemaVersion(99))
        }
    }

    func testResumeStateRules() {
        var checkpoint = InstallCheckpoint(updatedAt: InstallHarness.fixtureStart)
        XCTAssertEqual(checkpoint.resumeState, .preflight)

        checkpoint.lastCompletedState = .preflight
        XCTAssertEqual(checkpoint.resumeState, .preflight)
        checkpoint.lastCompletedState = .packageVerify
        XCTAssertEqual(checkpoint.resumeState, .preflight, "verification is never trusted across launches")
        checkpoint.lastCompletedState = .installCore
        XCTAssertEqual(checkpoint.resumeState, .createOwner)
        checkpoint.lastCompletedState = .selectAI
        XCTAssertEqual(checkpoint.resumeState, .bootServices)
        checkpoint.lastCompletedState = .healthCheck
        XCTAssertEqual(checkpoint.resumeState, .ready)

        checkpoint.runtime = InstallCheckpoint.RuntimeProgress(version: "0.1.0", stagingDirectoryName: ".staging-0.1.0", activated: false)
        XCTAssertTrue(checkpoint.hasPartialRuntime)
        checkpoint.runtime?.activated = true
        XCTAssertFalse(checkpoint.hasPartialRuntime)
    }

    func testOwnerDraftValidation() {
        XCTAssertTrue(OwnerDraft(name: "A", email: "a@example.org").isValid)
        XCTAssertEqual(OwnerDraft(name: " ", email: "").validate(), [.nameMissing, .emailMissing])
        XCTAssertEqual(OwnerDraft(name: "A", email: "a@b").validate(), [.emailInvalid])
        XCTAssertEqual(OwnerDraft(name: "A", email: "a b@example.org").validate(), [.emailInvalid])
        XCTAssertEqual(OwnerDraft(name: "A", email: "@example.org").validate(), [.emailInvalid])
    }

    func testAiSelectionCodable() throws {
        let encoder = JSONEncoder()
        let decoder = JSONDecoder()

        for selection in [AiSelection.skip, .detectedLocalRuntime(.lmStudio), .ownApiKey(.custom)] {
            let data = try encoder.encode(selection)
            XCTAssertEqual(try decoder.decode(AiSelection.self, from: data), selection)
        }

        XCTAssertTrue(AiSelection.skip.setupRequired)
        XCTAssertNil(TransientSecret("   "))
        XCTAssertEqual(AiSelectionSubmission(selection: .ownApiKey(.openAI)).validate(), .apiKeyMissing)
        XCTAssertNil(AiSelectionSubmission(selection: .skip).validate())
    }
}
