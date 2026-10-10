// S1-11, blueprint §0 / health contract §3, §7: no copy path of the menu bar may render "AI ready"
// (EN) or "AI redo" (SV) while `ai_ready == false`. Every health fixture plus a synthetic document
// for every `ai_state` × `ai_ready` combination, in both languages, through every text the status
// section can show. The AI-row details (provider, last success, the one action) are covered too.

import XCTest
@testable import LaravelExecutiveAI

@MainActor
final class MenuBarAiReadyGateTests: XCTestCase {

    static let phrases: [MenuBarLanguage: String] = [.en: "AI ready", .sv: "AI redo"]

    override func tearDown() {
        MenuBarCopy.use(nil)
        super.tearDown()
    }

    /// Every text the status section can render for the AI row and the panel state.
    private func renderedCopy(_ model: PanelModel) -> [String] {
        [model.aiStatusText, model.state.title, model.state.detail, model.aiProviderText ?? "", model.aiProviderNote ?? "",
         model.aiLastSuccessText ?? "", model.aiAction?.title ?? "", model.connectivityNote ?? ""]
    }

    private static func documents() throws -> [(name: String, health: HealthDocument)] {
        var documents = try HealthFixture.allCases.map { ($0.rawValue, try MenuBarFixtures.health($0)) }

        for base in [HealthFixture.aiReady, .coreReadyNoAI, .staleBriefOffline, .notReadySchedulerFailing] {
            var json = try XCTUnwrap(try JSONValue.parse(try Data(contentsOf: MenuBarFixtures.root.appending(path: "health/\(base.rawValue).json"))).objectValue)

            for state in ["not_configured", "configured_untested", "ready", "error", "some_future_state"] {
                for ready in [true, false] {
                    json["ai_state"] = .string(state)
                    json["ai_ready"] = .bool(ready)
                    json["ai_provider"] = state == "not_configured" ? .null : .string("ollama")
                    json["ai_last_success_at"] = ready ? .string("2026-10-10T17:45:12+02:00") : .null

                    documents.append(("\(base.rawValue) × \(state)/\(ready)", try HealthDocument.decode(try JSONEncoder().encode(JSONValue.object(json)))))
                }
            }
        }

        return documents
    }

    func testNoCopyPathSaysAiReadyUnlessAiReadyIsTrue() async throws {
        for language in MenuBarLanguage.allCases {
            MenuBarCopy.use(language)
            let phrase = try XCTUnwrap(Self.phrases[language])

            for document in try Self.documents() {
                let model = PanelModel(client: StaticCoreClient(health: document.health), cache: BriefCache(directory: MenuBarFixtures.temporaryDirectory()), ports: SpyPorts().ports)
                await model.refresh()

                let rendered = renderedCopy(model)
                let claimsReady = rendered.contains { $0.contains(phrase) }
                let isReady = document.health.aiReady && document.health.aiState == .ready

                XCTAssertEqual(claimsReady, isReady, "\(language.rawValue) \(document.name): \(rendered)")

                if isReady {
                    XCTAssertEqual(model.aiStatusText, MenuBarCopy.aiReady, document.name)
                    XCTAssertNil(model.aiAction, "\(document.name): no action while AI is ready")
                    XCTAssertNotNil(model.aiLastSuccessText, document.name)
                } else {
                    XCTAssertNotNil(model.aiAction, "\(document.name): Set up / Reconfigure AI is offered whenever AI is not ready")
                    XCTAssertNil(model.aiLastSuccessText, "\(document.name): last success is only shown while ready")
                    XCTAssertNotEqual(model.aiStatusText, MenuBarCopy.aiReady, document.name)
                }

                // The paused chip replaces the status text; it must not leak a ready claim either.
                await model.togglePauseAI()
                XCTAssertFalse(renderedCopy(model).contains { $0.contains(phrase) && $0 != MenuBarCopy.aiReady }, document.name)
            }
        }
    }

    func testLoadingAndUnreachableNeverSayAiReady() async {
        for language in MenuBarLanguage.allCases {
            MenuBarCopy.use(language)
            let phrase = Self.phrases[language] ?? ""

            let loading = PanelModel(client: UnreachableCoreClient(), cache: BriefCache(directory: MenuBarFixtures.temporaryDirectory()), ports: SpyPorts().ports)
            XCTAssertFalse(renderedCopy(loading).contains { $0.contains(phrase) }, language.rawValue)
            XCTAssertEqual(loading.aiAction, .setUp)

            await loading.refresh()
            XCTAssertEqual(loading.state, .coreUnreachable)
            XCTAssertFalse(renderedCopy(loading).contains { $0.contains(phrase) }, language.rawValue)
        }
    }

    func testAiRowDetailsFollowTheHealthFields() async throws {
        let (ready, _, spies) = makePanelModel(health: .aiReady)
        await ready.refresh()
        XCTAssertEqual(ready.aiProviderText, "Ollama", "catalogue display name, not the raw id")
        XCTAssertNil(ready.aiProviderNote)
        XCTAssertEqual(ready.aiLastSuccessText, RFC3339.display("2026-10-10T17:45:12+02:00"))
        XCTAssertNil(ready.aiAction)

        let (none, _, _) = makePanelModel(health: .coreReadyNoAI)
        await none.refresh()
        XCTAssertNil(none.aiProviderText)
        XCTAssertNil(none.aiLastSuccessText)
        XCTAssertEqual(none.aiAction, .setUp)
        XCTAssertEqual(none.aiAction?.title, MenuBarCopy.setUpAI)

        var json = try XCTUnwrap(try JSONValue.parse(try Data(contentsOf: MenuBarFixtures.root.appending(path: "health/ready-core-ready-no-ai.json"))).objectValue)
        json["ai_state"] = .string("configured_untested")
        json["ai_provider"] = .string("some-future-provider")
        let untested = PanelModel(client: StaticCoreClient(health: try HealthDocument.decode(try JSONEncoder().encode(JSONValue.object(json)))), cache: BriefCache(directory: MenuBarFixtures.temporaryDirectory()), ports: SpyPorts().ports)
        await untested.refresh()
        XCTAssertEqual(untested.aiProviderText, "some-future-provider", "an id the catalogue does not know is shown verbatim, never guessed")
        XCTAssertEqual(untested.aiProviderNote, MenuBarCopy.aiNoCompletionYet)
        XCTAssertEqual(untested.aiAction, .setUp)

        json["ai_state"] = .string("error")
        json["ai_provider"] = .string("anthropic")
        let error = PanelModel(client: StaticCoreClient(health: try HealthDocument.decode(try JSONEncoder().encode(JSONValue.object(json)))), cache: BriefCache(directory: MenuBarFixtures.temporaryDirectory()), ports: SpyPorts().ports)
        await error.refresh()
        XCTAssertEqual(error.aiProviderText, "Anthropic")
        XCTAssertEqual(error.aiAction, .reconfigure)
        XCTAssertEqual(error.aiAction?.title, MenuBarCopy.reconfigureAI)

        // The deep link lands in Settings for now and touches nothing else.
        ready.reconfigureAI()
        XCTAssertEqual(spies.settings.presentations, 1)
        XCTAssertEqual(spies.urlOpener.opened, [])
        XCTAssertEqual(spies.serviceControl.restarts, 0)
    }

    func testSwedishIsSelectedByTheLocaleLanguageOnly() {
        XCTAssertEqual(MenuBarLanguage.preferred(from: Locale(identifier: "sv_SE")), .sv)
        XCTAssertEqual(MenuBarLanguage.preferred(from: Locale(identifier: "sv")), .sv)
        XCTAssertEqual(MenuBarLanguage.preferred(from: Locale(identifier: "sv_FI")), .sv)
        XCTAssertEqual(MenuBarLanguage.preferred(from: Locale(identifier: "en_SE")), .en, "an English-speaking user in Sweden gets English")
        XCTAssertEqual(MenuBarLanguage.preferred(from: Locale(identifier: "nb_NO")), .en)
        XCTAssertEqual(MenuBarLanguage.preferred(from: Locale(identifier: "en_US")), .en)

        MenuBarCopy.use(.sv)
        XCTAssertEqual(MenuBarCopy.stateAiSetupRequired, "AI-konfiguration krävs")
        XCTAssertEqual(MenuBarCopy.aiReady, "AI redo")
        XCTAssertEqual(MenuBarCopy.badgeAccessibilityLabel(count: 1), "1 uppgift väntar på godkännande")
        XCTAssertEqual(MenuBarCopy.kindLabel("commitment"), "Åtagande")

        MenuBarCopy.use(.en)
        XCTAssertEqual(MenuBarCopy.stateAiSetupRequired, "AI setup required")
        XCTAssertEqual(MenuBarCopy.badgeAccessibilityLabel(count: 2), "2 tasks awaiting approval")
    }

    func testTheReadyPhraseExistsExactlyOncePerLanguageAndBothTablesAreComplete() {
        let strings = MenuBarCopy.allStrings

        XCTAssertEqual(strings.filter { $0.contains("AI ready") }, ["AI ready"])
        XCTAssertEqual(strings.filter { $0.contains("AI redo") }, ["AI redo"])

        // Both tables carry every field; a nil would have failed to compile, an empty string hides here.
        XCTAssertFalse(strings.contains(""), "every copy string has content")
        XCTAssertEqual(MenuBarStrings.en.all.count, MenuBarStrings.sv.all.count)
        XCTAssertEqual(Set(MenuBarStrings.en.kinds.keys), Set(MenuBarStrings.sv.kinds.keys))
        XCTAssertEqual(Set(MenuBarStrings.en.approvalKinds.keys), Set(MenuBarStrings.sv.approvalKinds.keys))
        XCTAssertEqual(Set(MenuBarStrings.en.sourceTypes.keys), Set(MenuBarStrings.sv.sourceTypes.keys))
        XCTAssertEqual(Set(MenuBarStrings.en.priorities.keys), Set(MenuBarStrings.sv.priorities.keys))
    }
}
