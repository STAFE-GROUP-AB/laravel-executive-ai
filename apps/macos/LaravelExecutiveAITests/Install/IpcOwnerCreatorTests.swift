// CREATE_OWNER over IPC (ipc-contract §6.9, issue #121): the three answers the core can give
// become `OwnerHandoff` / `OwnerCreationError` exactly as the state machine expects, the request
// reproduces the recorded `owner.json` exchange byte for byte, a reinstall's `409 owner_exists`
// is a hand-off and not a failure, nothing in the draft is logged, and the installer walks past
// CREATE_OWNER through the real creator in every case that should advance.

import Foundation
import Synchronization
import XCTest
@testable import LaravelExecutiveAI

final class IpcOwnerCreatorTests: XCTestCase {
    private static let draft = OwnerDraft(name: "Ada Owner", email: "ada.owner@example.test")

    override func setUp() {
        super.setUp()
        IpcStubURLProtocol.reset()
    }

    override func tearDown() {
        IpcStubURLProtocol.reset()
        super.tearDown()
    }

    /// Three polls a millisecond apart. The live creator waits up to 20 s for the core to come up
    /// (#124), which is right on hardware and absurd in a unit test: without this override every
    /// case in this file that does not answer `/health/live` burns the full 20 s before it can
    /// fail, which is how a suite stops being run locally.
    static func testWaiting() -> IpcOwnerCreator.Waiting {
        var waiting = IpcOwnerCreator.Waiting()
        waiting.attempts = 3
        waiting.interval = .milliseconds(1)

        return waiting
    }

    private func makeCreator(
        port: Int? = IpcTestSupport.port,
        session: IpcSession = IpcTestSupport.session(),
        timeZone: String = "Europe/Stockholm",
        language: String = "sv",
        nonce: String? = nil,
        requestId: String? = nil,
        sink: any IpcEventSink = NoopIpcSink()
    ) -> IpcOwnerCreator {
        IpcOwnerCreator(
            port: { port },
            session: session,
            timeZoneIdentifier: { timeZone },
            languageTag: { language },
            waiting: Self.testWaiting()
        ) { port, session in
            IpcTestSupport.client(port: port, session: session, nonce: nonce, requestId: requestId, sink: sink)
        }
    }

    /// `/health/live` first, the test's own answer for everything else. The creator waits for the
    /// core before its first signed request (#124), so a stub that does not answer liveness is a
    /// stub of a core that never came up — which is only what two cases here mean.
    private static func installServingCore(_ handler: @escaping IpcStubURLProtocol.Handler) {
        let live = Data("{\"status\":\"ok\"}".utf8)

        IpcStubURLProtocol.install { request, body in
            guard request.url?.path != "/health/live" else {
                return .response(status: 200, headers: ["Content-Type": "application/json"], body: live)
            }

            return handler(request, body)
        }
    }

    /// The signed exchange, without the unsigned `/health/live` poll that now precedes it (#124).
    private static var signedRequests: [IpcStubURLProtocol.Recorded] {
        IpcStubURLProtocol.requests.filter { $0.request.url?.path != "/health/live" }
    }

    private func createdBody() throws -> Data {
        try IpcTestSupport.successBody(data: .object(["created": .bool(true)]))
    }

    // MARK: - The three answers

    func testACreatedOwnerIsAHandoffThatDidNotAlreadyExist() async throws {
        let body = try createdBody()
        Self.installServingCore { request, _ in IpcTestSupport.signedReply(for: request, status: 200, body: body) }

        let handoff = try await makeCreator().createOwner(Self.draft)

        XCTAssertEqual(handoff, OwnerHandoff(alreadyExisted: false))

        XCTAssertEqual(IpcStubURLProtocol.requests.first?.request.url?.path, "/health/live", "the core is waited for before it is trusted")

        let recorded = try XCTUnwrap(Self.signedRequests.first)
        XCTAssertEqual(recorded.request.url?.absoluteString, "http://127.0.0.1:8787/ipc/v1/owner")
        XCTAssertEqual(recorded.request.httpMethod, "POST")
        XCTAssertEqual(recorded.header("Content-Type"), "application/json")
        XCTAssertTrue(recorded.header("Authorization")?.hasPrefix("Bearer lxai1.menubar.") == true)
        XCTAssertEqual(recorded.header("X-Lxai-Ipc-Version"), "1")
        XCTAssertNotNil(recorded.header("X-Lxai-Signature"))
        XCTAssertNil(recorded.header("X-Lxai-Elevation"), "§7.3: owner creation is not an elevated operation")
        IpcHeaderRules.assertClean(recorded.request)

        // The body is exactly the five §6.9 fields, in RFC 8785 canonical form.
        let sent = try JSONValue.parse(recorded.body)
        XCTAssertEqual(sent, .object([
            "email": .string("ada.owner@example.test"),
            "language": .string("sv"),
            "name": .string("Ada Owner"),
            "timezone": .string("Europe/Stockholm"),
        ]))
        XCTAssertFalse(String(decoding: recorded.body, as: UTF8.self).contains("password"))
    }

    func testAnExistingOwnerIsAHandoffAndNotAFailure() async throws {
        let body = try IpcTestSupport.errorBody(
            code: "owner_exists",
            message: "An owner account already exists on this Mac.",
            hint: "Continue the install: the existing account is kept and signs in with a one-time code."
        )
        Self.installServingCore { request, _ in IpcTestSupport.signedReply(for: request, status: 409, body: body) }

        let handoff = try await makeCreator().createOwner(Self.draft)

        XCTAssertEqual(handoff, OwnerHandoff(alreadyExisted: true))
        XCTAssertEqual(Self.signedRequests.count, 1, "the refusal is not retried")
    }

    func testTheRefusalIsStillVerifiedBeforeItIsBelieved() async throws {
        // An unsigned `409` is not one of the four answers that may arrive
        // unsigned (§4.5), so a hijacked port cannot talk the installer out of
        // creating the owner.
        let body = try IpcTestSupport.errorBody(code: "owner_exists")
        Self.installServingCore { _, _ in IpcTestSupport.unsignedReply(status: 409, body: body) }

        await XCTAssertThrowsErrorAsync(try await makeCreator().createOwner(Self.draft)) { error in
            XCTAssertEqual(error as? OwnerCreationError, .unavailable("core unreachable: the response carried no signature"))
        }
    }

    func testTheFixtureExchangeIsReproducedByteForByte() async throws {
        let fixture = try IpcFixture.load("owner")
        let reply = try fixture.recordedReply()
        Self.installServingCore { _, _ in reply }

        let creator = IpcOwnerCreator(
            port: { IpcTestSupport.port },
            session: IpcTestSupport.session(),
            timeZoneIdentifier: { "Europe/Stockholm" },
            languageTag: { "sv" }
        ) { port, session in
            IpcTestSupport.client(port: port, session: session, now: fixture.date, nonce: fixture.nonce, requestId: fixture.requestId)
        }

        let handoff = try await creator.createOwner(Self.draft)
        let recorded = try XCTUnwrap(Self.signedRequests.first)

        XCTAssertEqual(handoff, OwnerHandoff(alreadyExisted: false))
        XCTAssertEqual(recorded.request.httpMethod, fixture.method)
        XCTAssertEqual(recorded.request.url?.path, fixture.path)
        XCTAssertEqual(recorded.body, try fixture.requestBodyBytes())
        XCTAssertEqual(recorded.header("X-Lxai-Signature"), fixture.requestHeaders["X-Lxai-Signature"])
        XCTAssertEqual(recorded.header("X-Lxai-Timestamp"), fixture.requestHeaders["X-Lxai-Timestamp"])
    }

    func testTheOwnerExistsFixtureIsTheReinstallHandoff() async throws {
        let fixture = try IpcFixture.load("error-owner-exists")
        XCTAssertEqual(fixture.errorCode, "owner_exists")
        XCTAssertEqual(fixture.responseStatus, 409)

        let reply = try fixture.recordedReply()
        Self.installServingCore { _, _ in reply }

        let creator = IpcOwnerCreator(
            port: { IpcTestSupport.port },
            session: IpcTestSupport.session(),
            timeZoneIdentifier: { "Europe/Stockholm" },
            languageTag: { "sv" }
        ) { port, session in
            IpcTestSupport.client(port: port, session: session, now: fixture.date, nonce: fixture.nonce, requestId: fixture.requestId)
        }

        let handoff = try await creator.createOwner(Self.draft)
        XCTAssertEqual(handoff, OwnerHandoff(alreadyExisted: true))
    }

    // MARK: - Failures, split into what the person can act on

    func testAValidationFailureNamesTheFieldsAndIsRejected() async throws {
        let body = try IpcTestSupport.errorBody(code: "validation_failed", details: ["email": "invalid"])
        Self.installServingCore { request, _ in IpcTestSupport.signedReply(for: request, status: 422, body: body) }

        await XCTAssertThrowsErrorAsync(try await makeCreator().createOwner(Self.draft)) { error in
            XCTAssertEqual(error as? OwnerCreationError, .rejected("The core did not accept these details: email."))
        }
    }

    func testEveryErrorCodeLandsOnTheRightHalfOfTheSplit() throws {
        let rejected: [IpcClientError] = [
            .validationFailed(code: "validation_failed", details: [:]),
            .policyDenied(message: "The policy denied this operation."),
            .forbiddenScope,
            .originRejected,
            .stateConflict,
            .badRequest(code: "bad_request"),
            .unexpected(status: 418, code: "teapot"),
        ]
        let unavailable: [IpcClientError] = [
            .unreachable("no answer on loopback"),
            .supervisorUnreachable("no control channel"),
            .sessionInvalid,
            .signatureInvalid,
            .timestampSkew,
            .nonceReplayed,
            .secretsUnavailable(hint: nil, retryAfterSeconds: 5),
            .internalError(requestId: nil),
            .rateLimited(retryAfterSeconds: 30),
            .redirected(status: 302),
            .notFound,
            .versionUnsupported(serverMajor: 2, supportedVersions: [2], hint: nil),
        ]

        for error in rejected {
            guard case .rejected = IpcOwnerCreator.map(error) else {
                return XCTFail("\(error) must be a rejection the form can act on")
            }
        }

        for error in unavailable {
            guard case .unavailable = IpcOwnerCreator.map(error) else {
                return XCTFail("\(error) must be an unavailable core, not a rejected account")
            }
        }
    }

    /// Nothing listening at all: the creator never gets past the liveness wait, and says so. This
    /// is the case that failed on the owner's Mac, and the message is now about the core not
    /// being up rather than about pairing (#124).
    func testACoreThatIsNotUpYetIsUnavailableWithAnActionableReason() async throws {
        IpcStubURLProtocol.install { _, _ in .failure(.cannotConnectToHost) }

        await XCTAssertThrowsErrorAsync(try await makeCreator().createOwner(Self.draft)) { error in
            XCTAssertEqual(
                error as? OwnerCreationError,
                .unavailable("The core is not answering yet. The background service may need your permission in System Settings › Login Items.")
            )
        }

        XCTAssertEqual(Self.signedRequests.count, 0, "no signed request is sent to a core that is not there")
    }

    /// A core that answers liveness and then drops the signed request is a different failure, and
    /// still `.unavailable` — the form is not what is wrong.
    func testALiveCoreThatDropsTheSignedRequestIsStillUnavailable() async throws {
        Self.installServingCore { _, _ in .failure(.cannotConnectToHost) }

        await XCTAssertThrowsErrorAsync(try await makeCreator().createOwner(Self.draft)) { error in
            XCTAssertEqual(error as? OwnerCreationError, .unavailable("core unreachable: no answer on loopback"))
        }

        XCTAssertEqual(Self.signedRequests.count, 1)
    }

    func testACoreThatDoesNotKnowTheOperationAsksForARepair() async throws {
        let body = try IpcTestSupport.errorBody(code: "not_found")
        Self.installServingCore { request, _ in IpcTestSupport.signedReply(for: request, status: 404, body: body) }

        await XCTAssertThrowsErrorAsync(try await makeCreator().createOwner(Self.draft)) { error in
            XCTAssertEqual(error as? OwnerCreationError, .unavailable("This core does not offer owner creation over IPC; repair the installation."))
        }
    }

    func testASignedAnswerThatIsNotTheEnvelopeIsUnavailable() async throws {
        let body = Data("{\"created\":true}".utf8)
        Self.installServingCore { request, _ in IpcTestSupport.signedReply(for: request, status: 200, body: body) }

        await XCTAssertThrowsErrorAsync(try await makeCreator().createOwner(Self.draft)) { error in
            XCTAssertEqual(error as? OwnerCreationError, .unavailable("The core answered something that is not the IPC envelope."))
        }
    }

    func testNoRecordedPortAndAnInvalidDraftNeverReachTheWire() async throws {
        await XCTAssertThrowsErrorAsync(try await makeCreator(port: nil).createOwner(Self.draft)) { error in
            XCTAssertEqual(error as? OwnerCreationError, .unavailable("The core has no loopback port yet; run the install again from the start."))
        }

        await XCTAssertThrowsErrorAsync(try await makeCreator().createOwner(OwnerDraft(name: "", email: "nope"))) { error in
            XCTAssertEqual(error as? OwnerCreationError, .rejected("Check the name and the e-mail address."))
        }

        XCTAssertEqual(IpcStubURLProtocol.requests.count, 0, "not even a liveness poll")
    }

    func testThePortIsReadFromTheCheckpointWhenTheCallRuns() async throws {
        let store = InMemoryInstallCheckpointStore()
        let creator = IpcOwnerCreator(
            checkpointStore: store,
            session: IpcTestSupport.session(grant: IpcTestSupport.grant(port: 8791)),
            timeZoneIdentifier: { "Europe/Stockholm" },
            languageTag: { "sv" }
        ) { port, session in
            IpcTestSupport.client(port: port, session: session)
        }
        let body = try createdBody()
        Self.installServingCore { request, _ in IpcTestSupport.signedReply(for: request, status: 200, body: body, port: 8791) }

        await XCTAssertThrowsErrorAsync(try await creator.createOwner(Self.draft))

        var checkpoint = InstallCheckpoint(updatedAt: Date())
        checkpoint.preflight = InstallCheckpoint.PreflightSummary(port: 8791, language: .sv, existingInstall: .fresh, warnings: [], fileVaultEnabled: nil)
        try store.save(checkpoint)

        let handoff = try await creator.createOwner(Self.draft)
        XCTAssertEqual(handoff, OwnerHandoff(alreadyExisted: false))
        XCTAssertEqual(IpcStubURLProtocol.requests.last?.request.url?.port, 8791)
    }

    func testTheDraftNeverReachesTheClientLog() async throws {
        let body = try createdBody()
        Self.installServingCore { request, _ in IpcTestSupport.signedReply(for: request, status: 200, body: body) }
        let sink = CapturingIpcSink()

        _ = try await makeCreator(sink: sink).createOwner(Self.draft)

        XCTAssertFalse(sink.text.isEmpty)
        XCTAssertFalse(sink.text.contains("ada.owner"))
        XCTAssertFalse(sink.text.contains("Ada Owner"))
        XCTAssertFalse(sink.text.contains("lxai1"))
        XCTAssertEqual(sink.events.map(\.operation), ["owner"])
    }

    // MARK: - The installer, through the real creator

    @MainActor
    private func runInstall(_ handler: @escaping IpcStubURLProtocol.Handler) async throws -> (InstallStateMachine, InstallHarness) {
        let harness = try InstallHarness()
        var dependencies = harness.dependencies
        dependencies.ownerCreator = IpcOwnerCreator(
            checkpointStore: harness.checkpointStore,
            session: IpcTestSupport.session(),
            timeZoneIdentifier: { "Europe/Stockholm" },
            languageTag: { "sv" },
            waiting: Self.testWaiting()
        ) { port, session in
            IpcTestSupport.client(port: port, session: session)
        }

        Self.installServingCore(handler)

        let machine = InstallStateMachine(dependencies: dependencies)
        machine.start()
        await machine.proceedFromWelcome()
        await machine.submitOwner(InstallHarness.owner)

        return (machine, harness)
    }

    @MainActor
    func testTheInstallerPassesCreateOwnerOnAFreshInstall() async throws {
        let body = try createdBody()
        let (machine, harness) = try await runInstall { request, _ in
            IpcTestSupport.signedReply(for: request, status: 200, body: body, port: request.url?.port ?? 0)
        }
        defer { harness.tearDown() }

        XCTAssertEqual(machine.state, .selectAI)
        XCTAssertEqual(machine.status, .waitingForPerson)
        XCTAssertTrue(machine.history.contains(.createOwner))
        XCTAssertEqual(
            IpcStubURLProtocol.requests.map { $0.request.url?.path }.filter { $0 != "/health/live" },
            ["/ipc/v1/owner"]
        )

        let stored = try XCTUnwrap(try harness.checkpointStore.load())
        XCTAssertTrue(stored.ownerCreated)
    }

    @MainActor
    func testTheInstallerPassesCreateOwnerOnADataPreservingReinstall() async throws {
        let body = try IpcTestSupport.errorBody(code: "owner_exists", message: "An owner account already exists on this Mac.")
        let (machine, harness) = try await runInstall { request, _ in
            IpcTestSupport.signedReply(for: request, status: 409, body: body, port: request.url?.port ?? 0)
        }
        defer { harness.tearDown() }

        XCTAssertEqual(machine.state, .selectAI, "owner_exists advances the install; it is not a red box")
        XCTAssertEqual(machine.status, .waitingForPerson)
        XCTAssertTrue(try XCTUnwrap(try harness.checkpointStore.load()).ownerCreated)
    }

    @MainActor
    func testTheInstallerStopsOnCreateOwnerWhenTheCoreRefusesTheAccount() async throws {
        let body = try IpcTestSupport.errorBody(code: "validation_failed", details: ["email": "invalid"])
        let (machine, harness) = try await runInstall { request, _ in
            IpcTestSupport.signedReply(for: request, status: 422, body: body, port: request.url?.port ?? 0)
        }
        defer { harness.tearDown() }

        XCTAssertEqual(machine.state, .createOwner)

        guard case .failed(.ownerCreation(let reason)) = machine.status else {
            return XCTFail("expected CREATE_OWNER to fail, got \(machine.status)")
        }

        XCTAssertEqual(reason, "The core did not accept these details: email.")
        XCTAssertFalse(try XCTUnwrap(try harness.checkpointStore.load()).ownerCreated)
    }

    @MainActor
    func testTheInstallerStopsOnCreateOwnerWhenTheCoreIsUnreachable() async throws {
        let (machine, harness) = try await runInstall { _, _ in .failure(.cannotConnectToHost) }
        defer { harness.tearDown() }

        XCTAssertEqual(machine.state, .createOwner)

        guard case .failed(.ownerCreation(let reason)) = machine.status else {
            return XCTFail("expected CREATE_OWNER to fail, got \(machine.status)")
        }

        XCTAssertEqual(reason, "core unreachable: no answer on loopback")
        XCTAssertFalse(reason.contains("S1-02"), "the stand-in that never worked is gone")
    }
}
