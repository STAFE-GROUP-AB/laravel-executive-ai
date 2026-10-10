// The panel over the live client: `PanelModel` + `LoopbackIpcClient` against the recorded fixture
// exchanges (re-signed for the nonce actually used) renders the fresh state, the brief and the
// badge; a second refresh revalidates with `If-None-Match` and keeps the cache on `304`; "Pause
// AI actions" goes through `ai/pause`; a `426` becomes "Update required" with nothing else called.

import AppKit
import SwiftUI
import XCTest
@testable import LaravelExecutiveAI

@MainActor
final class IpcPanelIntegrationTests: XCTestCase {
    private var fixtures: [String: IpcFixture] = [:]

    override func setUpWithError() throws {
        try super.setUpWithError()
        IpcStubURLProtocol.reset()

        for name in ["health", "briefs-latest", "briefs-latest-not-modified", "approvals-pending", "heartbeat", "ai-pause", "ai-resume"] {
            fixtures[name] = try IpcFixture.load(name)
        }
    }

    override func tearDown() {
        IpcStubURLProtocol.reset()
        super.tearDown()
    }

    /// Answers every panel operation from the recorded exchange for its method and path.
    private func installFixtureServer() {
        let fixtures = self.fixtures

        IpcStubURLProtocol.install { request, _ in
            let path = request.url.map { $0.path() + ($0.query().map { "?\($0)" } ?? "") } ?? ""
            let name: String

            switch (request.httpMethod, path) {
            case ("GET", "/ipc/v1/health"): name = "health"
            case ("GET", "/ipc/v1/briefs/latest"):
                name = request.value(forHTTPHeaderField: "If-None-Match") == "\"01M4FJ7GY0PZH6YA3D9574XN5K:1\"" ? "briefs-latest-not-modified" : "briefs-latest"
            case ("GET", "/ipc/v1/approvals?state=pending&limit=20"): name = "approvals-pending"
            case ("POST", "/ipc/v1/heartbeat"): name = "heartbeat"
            case ("POST", "/ipc/v1/ai/pause"): name = "ai-pause"
            case ("POST", "/ipc/v1/ai/resume"): name = "ai-resume"
            default: return .failure(.badURL)
            }

            guard let fixture = fixtures[name], let reply = try? fixture.reply(for: request) else {
                return .failure(.badURL)
            }

            return reply
        }
    }

    private func makeModel(cacheDirectory: URL = MenuBarFixtures.temporaryDirectory(), spies: SpyPorts = SpyPorts()) -> (PanelModel, RecordingCoreClient) {
        let client = RecordingCoreClient(IpcTestSupport.client(session: IpcTestSupport.session()))
        let model = PanelModel(client: client, cache: BriefCache(directory: cacheDirectory), ports: spies.ports)

        return (model, client)
    }

    func testARefreshThroughTheLiveClientRendersTheFreshStateTheBriefAndTheBadge() async throws {
        installFixtureServer()
        let directory = MenuBarFixtures.temporaryDirectory()
        let (model, client) = makeModel(cacheDirectory: directory)

        model.openPanel()
        XCTAssertEqual(client.calls, [], "opening the panel sends nothing")

        await model.refresh()

        XCTAssertEqual(model.state, .fresh)
        XCTAssertEqual(model.badgeCount, 2)
        XCTAssertEqual(model.approvals.count, 2)
        XCTAssertEqual(model.brief?.id, "01M4FJ7GY0PZH6YA3D9574XN5K")
        XCTAssertEqual(model.briefETag, "\"01M4FJ7GY0PZH6YA3D9574XN5K:1\"")
        XCTAssertEqual(model.aiStatusText, MenuBarCopy.aiReady)
        XCTAssertEqual(model.privacyMode, "LOCAL_ONLY")
        XCTAssertEqual(client.calls, ["health", "briefs/latest", "approvals?state=pending", "heartbeat"])
        XCTAssertEqual(BriefCache(directory: directory).load()?.etag, "\"01M4FJ7GY0PZH6YA3D9574XN5K:1\"")

        let paths = IpcStubURLProtocol.requests.map { $0.request.url?.path() ?? "" }
        XCTAssertEqual(paths, ["/ipc/v1/health", "/ipc/v1/briefs/latest", "/ipc/v1/approvals", "/ipc/v1/heartbeat"])
        XCTAssertEqual(String(decoding: IpcStubURLProtocol.requests[3].body, as: UTF8.self).contains("\"panel_open\":true"), true, "the panel was open during the refresh")

        for recorded in IpcStubURLProtocol.requests {
            IpcHeaderRules.assertClean(recorded.request)
        }

        // Rendering: the panel and the label draw from the live answers.
        let renderer = ImageRenderer(content: MenuBarPanelView(model: model, scrolls: false).frame(width: 360).fixedSize(horizontal: false, vertical: true))
        renderer.scale = 1
        let image = try XCTUnwrap(renderer.nsImage)
        XCTAssertGreaterThan(image.size.height, 200)

        let label = try XCTUnwrap(ImageRenderer(content: MenuBarLabel(model: model)).nsImage)
        XCTAssertGreaterThan(label.size.width, 0)
    }

    func testASecondRefreshRevalidatesWithTheETagAndKeepsTheCacheOn304() async throws {
        installFixtureServer()
        let (model, client) = makeModel()

        await model.refresh()
        await model.refresh()

        XCTAssertEqual(client.calls.filter { $0.hasPrefix("briefs/latest") }, [
            "briefs/latest",
            "briefs/latest if-none-match \"01M4FJ7GY0PZH6YA3D9574XN5K:1\"",
        ])
        XCTAssertEqual(model.state, .fresh)
        XCTAssertEqual(model.brief?.id, "01M4FJ7GY0PZH6YA3D9574XN5K")

        let briefRequests = IpcStubURLProtocol.requests.filter { $0.request.url?.path() == "/ipc/v1/briefs/latest" }
        XCTAssertEqual(briefRequests.map { $0.header("If-None-Match") }, [nil, "\"01M4FJ7GY0PZH6YA3D9574XN5K:1\""])
    }

    func testPauseAndResumeGoThroughTheSignedOperations() async throws {
        installFixtureServer()
        let (model, client) = makeModel()

        await model.togglePauseAI()
        XCTAssertTrue(model.aiActionsPaused)
        XCTAssertFalse(model.pauseFailed)

        await model.togglePauseAI()
        XCTAssertFalse(model.aiActionsPaused)
        XCTAssertEqual(client.calls, ["ai/pause", "ai/resume"])

        let bodies = IpcStubURLProtocol.requests.map { String(decoding: $0.body, as: UTF8.self) }
        XCTAssertEqual(bodies, ["{\"reason\":\"user_menubar\"}", "{}"])
    }

    func testAVersionRefusalBecomesUpdateRequiredAndNothingElseIsCalled() async throws {
        installFixtureServer()
        let (model, client) = makeModel()
        await model.refresh()
        XCTAssertEqual(model.state, .fresh)
        XCTAssertEqual(model.badgeCount, 2)
        let callsBefore = client.calls.count

        let refused = try IpcFixture.load("error-version-unsupported")
        let reply = try refused.recordedReply()
        IpcStubURLProtocol.install { _, _ in reply }

        await model.refresh()

        XCTAssertEqual(model.state, .error(.updateRequired(found: "ipc/1")))
        XCTAssertEqual(model.state.title, MenuBarCopy.stateUpdateRequired)
        XCTAssertEqual(model.badgeCount, 0, "nothing can be acted on through a refused version; the earlier count does not linger")
        XCTAssertNotNil(model.brief, "the cached brief stays, nothing partial is fetched")
        XCTAssertEqual(Array(client.calls.dropFirst(callsBefore)), ["health"], "no brief, approvals or heartbeat after a version refusal")
        XCTAssertEqual(IpcStubURLProtocol.requests.count, 1)

        // The refusal clears as soon as the core answers properly again.
        installFixtureServer()
        await model.refresh()
        XCTAssertEqual(model.state, .fresh)
        XCTAssertEqual(model.badgeCount, 2)
        XCTAssertNil(model.ipcVersionRefused)
    }

    func testATamperedCoreIsUnreachableAndTheCachedBriefSurvives() async throws {
        let directory = MenuBarFixtures.temporaryDirectory()
        try BriefCache(directory: directory).store(document: try JSONValue.parse(try MenuBarFixtures.brief("brief-canonical")), etag: "\"01M4FJ7GY0PZH6YA3D9574XN5K:1\"")
        let health = try IpcTestSupport.successBody(data: try IpcTestSupport.healthFixtureData(.aiReady))
        IpcStubURLProtocol.install { _, _ in IpcTestSupport.unsignedReply(status: 200, body: health) }
        let (model, client) = makeModel(cacheDirectory: directory)

        await model.refresh()

        XCTAssertEqual(model.state, .coreUnreachable)
        XCTAssertNotNil(model.brief, "the cached brief is shown; the forged health is not")
        XCTAssertEqual(model.badgeCount, 0)
        XCTAssertEqual(client.calls, ["health"])
    }
}
