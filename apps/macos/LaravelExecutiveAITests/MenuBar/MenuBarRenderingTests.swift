// Rendering smoke test: the panel renders for every state without crashing, at the token width.
// With LXAI_MENUBAR_SCREENSHOT_DIR set, it also writes the state screenshots that
// docs/architecture/images/menubar/ holds (fixture data only; no hostnames, no home paths).

import AppKit
import SwiftUI
import XCTest
@testable import LaravelExecutiveAI

@MainActor
final class MenuBarRenderingTests: XCTestCase {

    func testPanelRendersInEveryState() async throws {
        let screenshotDirectory = ProcessInfo.processInfo.environment["LXAI_MENUBAR_SCREENSHOT_DIR"].map { URL(fileURLWithPath: $0) }

        if let screenshotDirectory {
            try FileManager.default.createDirectory(at: screenshotDirectory, withIntermediateDirectories: true)
        }

        let cases: [(name: String, model: PanelModel)] = [
            ("01-fresh", makePanelModel(health: .aiReady).model),
            ("02-stale-offline", makePanelModel(health: .staleBriefOffline).model),
            ("03-error-not-ready", makePanelModel(health: .notReadySchedulerFailing).model),
            ("05-ai-setup-required", makePanelModel(health: .coreReadyNoAI).model),
        ]

        for (name, model) in cases {
            await model.refresh()
            try render(model: model, name: name, into: screenshotDirectory)
        }

        let directory = MenuBarFixtures.temporaryDirectory()
        try BriefCache(directory: directory).store(document: try JSONValue.parse(try MenuBarFixtures.brief("brief-canonical")), etag: "\"01M4FJ7GY0PZH6YA3D9574XN5K:1\"")
        let unreachable = PanelModel(client: UnreachableCoreClient(), cache: BriefCache(directory: directory), ports: SpyPorts().ports)
        await unreachable.refresh()
        try render(model: unreachable, name: "04-core-unreachable", into: screenshotDirectory)
    }

    func testMenuBarLabelShowsTheCountOnlyWhenApprovalsArePending() async throws {
        let (fresh, _, _) = makePanelModel(health: .aiReady)
        await fresh.refresh()
        let withBadge = try XCTUnwrap(ImageRenderer(content: MenuBarLabel(model: fresh)).nsImage)

        let (stale, _, _) = makePanelModel(health: .staleBriefOffline)
        await stale.refresh()
        let withoutBadge = try XCTUnwrap(ImageRenderer(content: MenuBarLabel(model: stale)).nsImage)

        XCTAssertGreaterThan(withBadge.size.width, withoutBadge.size.width, "the count widens the label; stale alone does not")
    }

    private func render(model: PanelModel, name: String, into directory: URL?) throws {
        let renderer = ImageRenderer(content: MenuBarPanelView(model: model, scrolls: false).frame(width: 360).fixedSize(horizontal: false, vertical: true))
        renderer.scale = 2

        let image = try XCTUnwrap(renderer.nsImage, name)
        XCTAssertGreaterThan(image.size.height, 200, name)

        guard let directory else {
            return
        }

        let tiff = try XCTUnwrap(image.tiffRepresentation)
        let png = try XCTUnwrap(NSBitmapImageRep(data: tiff)?.representation(using: .png, properties: [:]))
        try png.write(to: directory.appending(path: "\(name).png"))
    }
}
