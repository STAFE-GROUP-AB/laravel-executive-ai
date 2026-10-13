// PanelModel against the fixture pack: the §7 state table, the badge rule, the §14 "open reads the
// cache only" rule, the ETag flow, and "Quit UI" ≠ stopping services.

import XCTest
@testable import LaravelExecutiveAI

@MainActor
final class PanelModelTests: XCTestCase {

    // MARK: - §14: opening the panel performs no network call

    func testOpeningThePanelPerformsNoClientCall() throws {
        let directory = MenuBarFixtures.temporaryDirectory()
        let cache = BriefCache(directory: directory)
        try cache.store(document: try JSONValue.parse(try MenuBarFixtures.brief("brief-canonical")), etag: "\"01M4FJ7GY0PZH6YA3D9574XN5K:1\"")

        let (model, client, _) = makePanelModel(health: .aiReady, cacheDirectory: directory)

        model.openPanel()
        _ = model.state
        _ = model.badgeCount
        _ = model.brief
        _ = model.generatedAtText
        _ = model.actionShortcuts
        model.closePanel()

        XCTAssertEqual(client.calls, [])
        XCTAssertEqual(model.brief?.id, "01M4FJ7GY0PZH6YA3D9574XN5K")
        XCTAssertEqual(model.briefETag, "\"01M4FJ7GY0PZH6YA3D9574XN5K:1\"")
        XCTAssertEqual(model.state, .stale, "a cached brief without a health answer renders as stale, never as fresh")
    }

    func testOpeningThePanelWithAnEmptyCacheStillPerformsNoClientCall() {
        let (model, client, _) = makePanelModel(health: .aiReady)

        model.openPanel()

        XCTAssertEqual(client.callCount, 0)
        XCTAssertNil(model.brief)
        XCTAssertEqual(model.state, .loading)
        XCTAssertEqual(model.badgeCount, 0)
    }

    // MARK: - Health contract §7 state table

    func testFreshStateFromTheAiReadyFixture() async {
        let (model, client, _) = makePanelModel(health: .aiReady)

        await model.refresh()

        XCTAssertEqual(model.state, .fresh)
        XCTAssertEqual(model.aiStatusText, MenuBarCopy.aiReady)
        XCTAssertNil(model.connectivityNote)
        XCTAssertEqual(model.privacyMode, "LOCAL_ONLY")
        XCTAssertEqual(model.generatedAtText, "2026-10-10T17:45:30+02:00")
        XCTAssertEqual(model.latestBriefVersion, 3)
        XCTAssertEqual(model.brief?.subject.isEmpty, false)
        XCTAssertEqual(client.calls, ["health", "briefs/latest", "approvals?state=pending", "heartbeat"])
    }

    func testAiSetupRequiredWheneverAiReadyIsFalse() async {
        let (model, _, _) = makePanelModel(health: .coreReadyNoAI)

        await model.refresh()

        XCTAssertEqual(model.state, .aiSetupRequired)
        XCTAssertEqual(model.aiStatusText, MenuBarCopy.stateAiSetupRequired)
        XCTAssertNotEqual(model.aiStatusText, MenuBarCopy.aiReady)
        XCTAssertEqual(model.badgeCount, 0)
    }

    func testStaleBriefOfflineRendersStaleWithAnOfflineNote() async {
        let (model, _, _) = makePanelModel(health: .staleBriefOffline)

        await model.refresh()

        XCTAssertEqual(model.state, .stale)
        XCTAssertTrue(model.briefStateIsStale)
        XCTAssertEqual(model.connectivityNote, MenuBarCopy.offline)
        XCTAssertEqual(model.aiStatusText, MenuBarCopy.aiReady, "offline does not change the AI row")
        XCTAssertNotNil(model.brief, "the cached brief is still shown")
    }

    func testNotReadyWinsOverBriefErrorAndAiSetup() async {
        let (model, _, _) = makePanelModel(health: .notReadySchedulerFailing)

        await model.refresh()

        XCTAssertEqual(model.state, .error(.notReady(failingChecks: ["scheduler_selftest"])))
        XCTAssertTrue(model.state.detail.contains("scheduler_selftest"))
        XCTAssertFalse(model.state.detail.contains("launchd"), "check detail text never crosses into the panel")
        XCTAssertEqual(model.aiStatusText, MenuBarCopy.stateAiSetupRequired)
        XCTAssertEqual(model.connectivityNote, MenuBarCopy.degraded)
        XCTAssertEqual(model.privacyMode, "EU_RESTRICTED")
    }

    func testCoreUnreachableWhenHealthFails() async throws {
        let directory = MenuBarFixtures.temporaryDirectory()
        try BriefCache(directory: directory).store(document: try JSONValue.parse(try MenuBarFixtures.brief("brief-canonical")), etag: "\"01M4FJ7GY0PZH6YA3D9574XN5K:1\"")
        let spies = SpyPorts()
        let client = RecordingCoreClient(UnreachableCoreClient())
        let model = PanelModel(client: client, cache: BriefCache(directory: directory), ports: spies.ports)

        await model.refresh()

        XCTAssertEqual(model.state, .coreUnreachable)
        XCTAssertEqual(model.state.systemImage, "bolt.slash")
        XCTAssertNotNil(model.brief, "the cached brief survives an unreachable core")
        XCTAssertEqual(client.calls, ["health"], "nothing else is tried when health fails")
        XCTAssertEqual(model.badgeCount, 0)
    }

    func testEveryStateRendersDistinctly() async {
        var seen: [PanelState: (String, String)] = [:]

        for fixture in HealthFixture.allCases {
            let (model, _, _) = makePanelModel(health: fixture)
            await model.refresh()
            seen[model.state] = (model.state.title, model.state.systemImage)
        }

        let unreachable = PanelModel(client: UnreachableCoreClient(), cache: BriefCache(directory: MenuBarFixtures.temporaryDirectory()), ports: SpyPorts().ports)
        await unreachable.refresh()
        seen[unreachable.state] = (unreachable.state.title, unreachable.state.systemImage)

        XCTAssertEqual(Set(seen.keys), [.fresh, .aiSetupRequired, .stale, .error(.notReady(failingChecks: ["scheduler_selftest"])), .coreUnreachable])
        XCTAssertEqual(Set(seen.values.map(\.0)).count, 5, "five distinct titles")
        XCTAssertEqual(Set(seen.values.map(\.1)).count, 5, "five distinct icons")
    }

    func testBriefErrorWinsOverStaleAndAiStatesWhenCoreIsReady() async throws {
        var json = try XCTUnwrap(try JSONValue.parse(try Data(contentsOf: MenuBarFixtures.root.appending(path: "health/ready-core-ready-no-ai.json"))).objectValue)
        json["brief_state"] = .string("error")
        let document = try HealthDocument.decode(try JSONEncoder().encode(JSONValue.object(json)))
        let model = PanelModel(client: StaticCoreClient(health: document), cache: BriefCache(directory: MenuBarFixtures.temporaryDirectory()), ports: SpyPorts().ports)

        await model.refresh()

        XCTAssertEqual(model.state, .error(.brief))

        json["brief_state"] = .string("fresh")
        json["ai_state"] = .string("error")
        let aiError = PanelModel(client: StaticCoreClient(health: try HealthDocument.decode(try JSONEncoder().encode(JSONValue.object(json)))), cache: BriefCache(directory: MenuBarFixtures.temporaryDirectory()), ports: SpyPorts().ports)

        await aiError.refresh()

        XCTAssertEqual(aiError.state, .error(.ai))
        XCTAssertEqual(aiError.aiStatusText, MenuBarCopy.stateAiError)
    }

    // MARK: - Badge: pending_approvals only

    func testBadgeOnlyWhenPendingApprovalsIsPositive() async {
        let (fresh, _, _) = makePanelModel(health: .aiReady)
        await fresh.refresh()
        XCTAssertEqual(fresh.badgeCount, 2)
        XCTAssertEqual(fresh.approvals.count, 2)
        XCTAssertEqual(fresh.approvals.first?.openUrl, "http://127.0.0.1:8787/approvals/01M4FJ7XM84RV70ZSNAMPQ03HX")

        for fixture in [HealthFixture.staleBriefOffline, .notReadySchedulerFailing, .coreReadyNoAI] {
            let (model, _, _) = makePanelModel(health: fixture)
            await model.refresh()
            XCTAssertEqual(model.badgeCount, 0, "\(fixture.rawValue): stale, offline, error and AI states never badge")
            XCTAssertEqual(model.approvals, [], fixture.rawValue)
        }
    }

    func testAStaleBriefWithPendingApprovalsStillBadgesTheCountOnly() async throws {
        var json = try XCTUnwrap(try JSONValue.parse(try Data(contentsOf: MenuBarFixtures.root.appending(path: "health/ready-stale-brief-offline.json"))).objectValue)
        json["pending_approvals"] = .number(1)
        let model = PanelModel(client: StaticCoreClient(health: try HealthDocument.decode(try JSONEncoder().encode(JSONValue.object(json)))), cache: BriefCache(directory: MenuBarFixtures.temporaryDirectory()), ports: SpyPorts().ports)

        await model.refresh()

        XCTAssertEqual(model.state, .stale)
        XCTAssertEqual(model.badgeCount, 1)
    }

    // MARK: - Cache and ETag flow (ipc-contract §6.2)

    func testRefreshStoresTheBriefAndRevalidatesWithTheETag() async throws {
        let directory = MenuBarFixtures.temporaryDirectory()
        let (model, client, _) = makePanelModel(health: .aiReady, brief: "brief-canonical", cacheDirectory: directory)

        await model.refresh()

        XCTAssertEqual(model.briefETag, "\"01M4FJ7GY0PZH6YA3D9574XN5K:1\"")
        XCTAssertEqual(BriefCache(directory: directory).load()?.etag, "\"01M4FJ7GY0PZH6YA3D9574XN5K:1\"")

        await model.refresh()

        XCTAssertEqual(client.calls.filter { $0.hasPrefix("briefs/latest") }, [
            "briefs/latest",
            "briefs/latest if-none-match \"01M4FJ7GY0PZH6YA3D9574XN5K:1\"",
        ], "health says v3 and the cache holds v1, so every refresh revalidates; the fixture answers 304")

        let reopened = PanelModel(client: RecordingCoreClient(UnreachableCoreClient()), cache: BriefCache(directory: directory), ports: SpyPorts().ports)
        XCTAssertEqual(reopened.brief?.contentHash, model.brief?.contentHash, "a new process opens from the cache")
    }

    func testRefreshSkipsTheBriefFetchWhenHealthMatchesTheCachedETag() async throws {
        let directory = MenuBarFixtures.temporaryDirectory()
        try BriefCache(directory: directory).store(document: try JSONValue.parse(try MenuBarFixtures.brief("brief-canonical")), etag: "\"01M4FJ7GY0PZH6YA3D9574XN5K:3\"")
        let (model, client, _) = makePanelModel(health: .aiReady, cacheDirectory: directory)

        await model.refresh()

        XCTAssertFalse(client.calls.contains { $0.hasPrefix("briefs/latest") })
        XCTAssertEqual(model.state, .fresh)
    }

    func testANewerSchemaMajorIsRefusedAndTheCacheIsNotOverwritten() async throws {
        let directory = MenuBarFixtures.temporaryDirectory()
        var json = try XCTUnwrap(try JSONValue.parse(try MenuBarFixtures.brief("brief-canonical")).objectValue)
        json["schema_version"] = .string("2.0.0")
        let health = try MenuBarFixtures.health(.aiReady)
        let client = StaticCoreClient(health: health, brief: .document(try JSONEncoder().encode(JSONValue.object(json)), etag: "\"x:9\""))
        let model = PanelModel(client: client, cache: BriefCache(directory: directory), ports: SpyPorts().ports)

        await model.refresh()

        XCTAssertEqual(model.state, .error(.updateRequired(found: "2.0.0")))
        XCTAssertNil(model.brief)
        XCTAssertNil(BriefCache(directory: directory).load())
    }

    // MARK: - Actions (§1.1)

    /// ADR-006 D3 (#109): every URL the panel hands to a browser is on `localhost`, the one
    /// loopback host that can be a WebAuthn relying party, while `client.origin` stays the
    /// `127.0.0.1` the IPC contract requires. `PwaOriginTests` owns the rewriting rules.
    func testOpenFullBriefAndOpenPWAUseTheCanonicalBrowserOrigin() async {
        let (model, client, spies) = makePanelModel(health: .aiReady)
        await model.refresh()

        model.openFullBrief()
        model.openPWA()
        model.openDiagnostics()
        model.openAction(id: "01M4FJ7WN0BRGSZJGNGZ5FSVT5")
        // The core still produces `open_url` on the IPC address (the fixture pack records it);
        // the panel canonicalises it rather than relying on the core's redirect.
        model.openApproval(PendingApproval(
            id: "01M4FJ7XM84RV70ZSNAMPQ03HX",
            kind: "mail.send",
            title: "Reply to a customer",
            summary: "",
            createdAt: "2026-10-10T08:00:00Z",
            expiresAt: "2026-10-10T08:15:00Z",
            briefItemId: nil,
            openUrl: "http://127.0.0.1:8787/approvals/01M4FJ7XM84RV70ZSNAMPQ03HX"
        ))

        XCTAssertEqual(spies.urlOpener.opened.map(\.absoluteString), [
            "http://localhost:8787/briefs/01M4FJ7GY0PZH6YA3D9574XN5K",
            "http://localhost:8787/home",
            "http://localhost:8787/admin",
            "http://localhost:8787/approvals/01M4FJ7WN0BRGSZJGNGZ5FSVT5",
            "http://localhost:8787/approvals/01M4FJ7XM84RV70ZSNAMPQ03HX",
        ])
        XCTAssertEqual(client.origin.absoluteString, "http://127.0.0.1:8787", "the IPC origin is unchanged by any of this")
        XCTAssertTrue(spies.urlOpener.opened.allSatisfy { $0.host() == "localhost" })
    }

    func testPauseAndResumeAiActionsToggleThroughTheClient() async {
        let (model, client, _) = makePanelModel(health: .aiReady)

        await model.togglePauseAI()
        XCTAssertTrue(model.aiActionsPaused)
        XCTAssertEqual(model.aiStatusText, MenuBarCopy.aiPaused)

        await model.togglePauseAI()
        XCTAssertFalse(model.aiActionsPaused)
        XCTAssertEqual(client.calls, ["ai/pause", "ai/resume"])
    }

    func testPauseStateFollowsHealthWhenALaterMinorReportsIt() async throws {
        var json = try XCTUnwrap(try JSONValue.parse(try Data(contentsOf: MenuBarFixtures.root.appending(path: "health/ready-ai-ready.json"))).objectValue)
        json["ai_actions"] = .string("paused")
        let model = PanelModel(client: StaticCoreClient(health: try HealthDocument.decode(try JSONEncoder().encode(JSONValue.object(json)))), cache: BriefCache(directory: MenuBarFixtures.temporaryDirectory()), ports: SpyPorts().ports)

        await model.refresh()

        XCTAssertTrue(model.aiActionsPaused)
    }

    func testQuitUITerminatesTheGuiOnlyAndNeverTouchesServiceControl() async {
        let (model, client, spies) = makePanelModel(health: .aiReady)
        await model.refresh()
        let callsBefore = client.callCount

        model.quitUI()

        XCTAssertEqual(spies.terminator.terminations, 1)
        XCTAssertEqual(spies.serviceControl.stops, 0)
        XCTAssertEqual(spies.serviceControl.uninstalls, 0)
        XCTAssertEqual(spies.serviceControl.restarts, 0)
        XCTAssertEqual(client.callCount, callsBefore, "Quit UI sends nothing to the core")
    }

    func testRestartServicesIsTheOnlyServiceControlPath() async {
        let (model, _, spies) = makePanelModel(health: .notReadySchedulerFailing)

        await model.restartServices()
        model.openSettings()

        XCTAssertEqual(spies.serviceControl.restarts, 1)
        XCTAssertEqual(spies.serviceControl.stops, 0)
        XCTAssertEqual(spies.serviceControl.uninstalls, 0)
        XCTAssertEqual(spies.settings.presentations, 1)
        XCTAssertEqual(spies.terminator.terminations, 0)
    }

    func testActionShortcutsAreTheItemsWithActionIds() async {
        let (model, _, _) = makePanelModel(health: .aiReady)
        await model.refresh()

        XCTAssertEqual(model.actionShortcuts.map(\.id), ["01M4FJ7HX8XJJ556PHW82CAXMN", "01M4FJ7KVR644NVGHTFEGR4VEY"])
    }

    func testLiveModelUnderXCTestUsesAnUnreachableClientAndATemporaryCache() {
        let model = PanelModel.live()

        XCTAssertTrue(model.client is UnreachableCoreClient)
        XCTAssertTrue(model.cache.fileURL.path.contains("lxai-menubar-test-"))
        XCTAssertEqual(model.refreshCount, 0)
    }
}

/// Answers a fixed health document (and optionally a brief) so a test can vary single fields.
struct StaticCoreClient: CoreClient {
    let origin = URL(string: "http://127.0.0.1:8787")!
    let health: HealthDocument
    var brief: LatestBriefResponse = .notModified(etag: "")

    func health() async throws -> HealthDocument { health }
    func latestBrief(ifNoneMatch etag: String?) async throws -> LatestBriefResponse { brief }
    func pendingApprovals() async throws -> PendingApprovals { .none }
    func pauseAI() async throws -> AiActionsState { AiActionsState(aiActions: "paused", changedAt: "", pausedSince: "", inFlightRunsCancelled: 0) }
    func resumeAI() async throws -> AiActionsState { AiActionsState(aiActions: "active", changedAt: "", pausedSince: nil, inFlightRunsCancelled: 0) }
    func heartbeat(panelOpen: Bool) async throws -> HeartbeatAck { HeartbeatAck(receivedAt: "", nextHeartbeatInSeconds: 30, thresholdSeconds: 120) }
}
