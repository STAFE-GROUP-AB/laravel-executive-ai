// SELECT_AI (S1-11; §12.3 screen 5, §1.1): the options come from the shared provider catalogue,
// nothing is preselected, a detected runtime is suggested without being selected, and the list
// equals packaging/fixtures/ai-providers/ai-providers.json byte for byte in meaning.

import AppKit
import SwiftUI
import XCTest
@testable import LaravelExecutiveAI

final class ChooseAiScreenTests: XCTestCase {

    private struct Fixture: Decodable {
        let schemaVersion: String
        let providers: [AiProviderCatalogEntry]
    }

    private static func fixture() throws -> Fixture {
        let url = TestFiles.repositoryRoot.appending(path: "packaging/fixtures/ai-providers/ai-providers.json")
        let decoder = JSONDecoder()
        decoder.keyDecodingStrategy = .convertFromSnakeCase

        return try decoder.decode(Fixture.self, from: try Data(contentsOf: url))
    }

    // MARK: - The shared catalogue

    func testCatalogueMirrorsTheSharedFixture() throws {
        let fixture = try Self.fixture()

        XCTAssertEqual(fixture.schemaVersion, AiProviderCatalog.schemaVersion)
        XCTAssertEqual(AiProviderCatalog.all, fixture.providers, "apps/macos AiProviderCatalog must equal packaging/fixtures/ai-providers/ai-providers.json")
        XCTAssertEqual(Set(AiProviderCatalog.all.map(\.id)).count, AiProviderCatalog.all.count, "ids are unique")
    }

    func testCatalogueIdsResolveToTheInstallerTypesWithTheSameNames() {
        for entry in AiProviderCatalog.all {
            switch entry.kind {
            case .local:
                let runtime = [LocalAiRuntime.ollama, .lmStudio].first { $0.id == entry.id }
                XCTAssertEqual(runtime?.displayName, entry.displayName, entry.id)
            case .remote:
                XCTAssertEqual(AiProviderID(rawValue: entry.id)?.displayName, entry.displayName, entry.id)
            }
        }
    }

    func testOptionListEqualsTheCatalogueSelectableSet() {
        let options = ChooseAiOptions(detected: [.ollama, .lmStudio])
        let selectableLocal = AiProviderCatalog.selectable.filter { $0.kind == .local }.map(\.id)
        let selectableRemote = AiProviderCatalog.selectable.filter { $0.kind == .remote }.compactMap { AiProviderID(rawValue: $0.id) }

        XCTAssertEqual(options.selectableRuntimes.map(\.id), selectableLocal)
        XCTAssertEqual(options.remoteProviders, selectableRemote)
        XCTAssertEqual(options.suggestedRuntimes.map(\.id), [LocalAiRuntime.ollama, .lmStudio].map(\.id).filter { !selectableLocal.contains($0) })
        XCTAssertEqual(options.onlySkipIsSelectable, AiProviderCatalog.selectable.isEmpty, "with no adapter in this build only skip can be chosen, and the screen says so")
    }

    func testOnlyAdapterBackedProvidersBecomeOptions() {
        let catalog = [
            AiProviderCatalogEntry(id: "ollama", displayName: "Ollama", kind: .local, driver: "ollama", adapterAvailable: true),
            AiProviderCatalogEntry(id: "lmstudio", displayName: "LM Studio", kind: .local, driver: "openai-compatible", adapterAvailable: false),
            AiProviderCatalogEntry(id: "openai", displayName: "OpenAI", kind: .remote, driver: "openai", adapterAvailable: true),
            AiProviderCatalogEntry(id: "anthropic", displayName: "Anthropic", kind: .remote, driver: "anthropic", adapterAvailable: false),
            AiProviderCatalogEntry(id: "custom", displayName: "Other", kind: .remote, driver: "openai-compatible", adapterAvailable: true),
        ]

        let options = ChooseAiOptions(detected: [.lmStudio, .ollama], catalog: catalog)

        XCTAssertEqual(options.selectableRuntimes, [.ollama])
        XCTAssertEqual(options.suggestedRuntimes, [.lmStudio])
        XCTAssertEqual(options.remoteProviders, [.openAI, .custom], "catalogue order, only adapter-backed, Anthropic not offered")
        XCTAssertTrue(options.offersOwnKey)
        XCTAssertFalse(options.onlySkipIsSelectable)
    }

    // MARK: - No preselection

    func testNothingIsPreselectedOnFirstRender() {
        let draft = ChooseAiDraft()

        XCTAssertNil(draft.choice, "no option is selected")
        XCTAssertNil(draft.provider, "no brand is preselected in the key picker")
        XCTAssertFalse(draft.canContinue, "Continue is disabled until a choice is made")
        XCTAssertNil(draft.submission())
        XCTAssertFalse(draft.needsProvider)
        XCTAssertFalse(draft.needsApiKey)
    }

    func testDetectedRuntimeIsSuggestedButNotSelected() {
        let withoutAdapter = ChooseAiOptions(detected: [.ollama])
        XCTAssertEqual(withoutAdapter.suggestedRuntimes, [.ollama])
        XCTAssertEqual(withoutAdapter.selectableRuntimes, [])

        let withAdapter = ChooseAiOptions(detected: [.ollama], catalog: [
            AiProviderCatalogEntry(id: "ollama", displayName: "Ollama", kind: .local, driver: "ollama", adapterAvailable: true),
        ])
        XCTAssertEqual(withAdapter.selectableRuntimes, [.ollama], "with its adapter the runtime is an option")
        XCTAssertEqual(withAdapter.suggestedRuntimes, [])

        // Either way the selection starts empty: detection never chooses.
        XCTAssertNil(ChooseAiDraft().choice)
    }

    func testSkipIsAChoiceThatEnablesContinue() {
        var draft = ChooseAiDraft()
        draft.choice = .skip

        XCTAssertTrue(draft.canContinue)
        XCTAssertEqual(draft.submission()?.selection, .skip)
        XCTAssertNil(draft.submission()?.apiKey)
    }

    func testOwnKeyNeedsAnExplicitProviderAndAKey() {
        var draft = ChooseAiDraft()
        draft.choice = .ownApiKey

        XCTAssertTrue(draft.canContinue, "Continue is enabled so the validation can show")
        XCTAssertTrue(draft.needsProvider, "no provider is preselected, so one must be chosen")
        XCTAssertTrue(draft.needsApiKey)
        XCTAssertNil(draft.submission())

        draft.provider = .anthropic
        XCTAssertFalse(draft.needsProvider)
        XCTAssertNil(draft.submission(), "still no key")

        draft.apiKey = "  sk-FAKE-0000  "
        XCTAssertEqual(draft.submission()?.selection, .ownApiKey(.anthropic))
        XCTAssertNotNil(draft.submission()?.apiKey)
        XCTAssertFalse(draft.description.contains("sk-FAKE"), "the draft never prints the key: \(draft.description)")
    }

    func testLocalRuntimeChoiceSubmitsThatRuntime() {
        var draft = ChooseAiDraft()
        draft.choice = .localRuntime(.lmStudio)

        XCTAssertEqual(draft.submission()?.selection, .detectedLocalRuntime(.lmStudio))
    }

    // MARK: - Rendering

    @MainActor
    func testScreenRendersWithNothingSelectedAndWritesTheDocumentationScreenshots() async throws {
        let harness = try InstallHarness()
        defer { harness.tearDown() }

        let machine = harness.makeMachine()
        machine.start()
        await machine.proceedFromWelcome()
        await machine.submitOwner(InstallHarness.owner)

        XCTAssertEqual(machine.state, .selectAI)
        XCTAssertEqual(machine.detectedRuntimes, [.ollama], "the harness detects Ollama; the screen suggests it without selecting it")

        let screenshotDirectory = ProcessInfo.processInfo.environment["LXAI_INSTALL_SCREENSHOT_DIR"].map { URL(fileURLWithPath: $0) }

        try render(ChooseAiScreen(machine: machine), name: "05-choose-ai", into: screenshotDirectory)
        try render(ChooseAiScreen(machine: machine, draft: ChooseAiDraft(choice: .skip)), name: "05-choose-ai-selected", into: screenshotDirectory)
    }

    @MainActor
    private func render(_ screen: ChooseAiScreen, name: String, into directory: URL?) throws {
        let renderer = ImageRenderer(content: screen.frame(width: 640, height: 640).background(Color(nsColor: .windowBackgroundColor)))
        renderer.scale = 2

        let image = try XCTUnwrap(renderer.nsImage, name)
        XCTAssertGreaterThan(image.size.height, 400, name)

        guard let directory else {
            return
        }

        try FileManager.default.createDirectory(at: directory, withIntermediateDirectories: true)
        let tiff = try XCTUnwrap(image.tiffRepresentation)
        let png = try XCTUnwrap(NSBitmapImageRep(data: tiff)?.representation(using: .png, properties: [:]))
        try png.write(to: directory.appending(path: "\(name).png"))
    }
}
