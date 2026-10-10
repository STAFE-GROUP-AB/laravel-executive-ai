// §11 / threat model T-33, T-34: no menu bar surface carries a token, the pairing grant or a
// signing key; ADR-005 D1.6: no approve/reject exists in the menu bar; the state icons resolve.

import AppKit
import XCTest
@testable import LaravelExecutiveAI

final class MenuBarCopyTests: XCTestCase {

    static let forbidden = ["lxai1.", "Bearer", "signing_key", "X-Lxai-Signature", "Authorization"]

    func testNoCopyStringContainsASecretMarker() {
        for string in MenuBarCopy.allStrings {
            for marker in Self.forbidden {
                XCTAssertFalse(string.localizedCaseInsensitiveContains(marker), "\"\(string)\" contains \(marker)")
            }
        }
    }

    @MainActor
    func testRenderedStateCopyNeverContainsASecretMarker() async throws {
        for fixture in HealthFixture.allCases {
            let (model, _, _) = makePanelModel(health: fixture)
            await model.refresh()

            var rendered = [model.state.title, model.state.detail, model.aiStatusText, model.connectivityNote ?? "", model.privacyMode ?? ""]
            rendered += model.approvals.flatMap { [$0.title, $0.summary, $0.openUrl] }

            if let brief = model.brief {
                rendered += [brief.subject, brief.summary]
                rendered += brief.items.flatMap { [$0.title, $0.body] + $0.sourceRefs.map { "\($0.accountLabel ?? "") \($0.title ?? "") \($0.uri)" } }
            }

            for string in rendered {
                for marker in Self.forbidden {
                    XCTAssertFalse(string.contains(marker), "\(fixture.rawValue): rendered text contains \(marker)")
                }
            }
        }
    }

    func testMenuBarSourcesDefineNoApproveOrRejectPath() throws {
        let sources = try FileManager.default.contentsOfDirectory(at: MenuBarFixtures.menuBarSources, includingPropertiesForKeys: nil)
            .filter { $0.pathExtension == "swift" }

        XCTAssertGreaterThan(sources.count, 5)

        for file in sources {
            let text = try String(contentsOf: file, encoding: .utf8)

            for symbol in ["approve(", "reject(", "func approve", "func reject", "/approve\"", "/reject\""] {
                XCTAssertFalse(text.contains(symbol), "\(file.lastPathComponent) contains \(symbol)")
            }
        }

        // Type-level: the client surface the panel is allowed to call.
        let operations = Mirror(reflecting: UnreachableCoreClient()).children.map(\.label)
        XCTAssertEqual(operations.compactMap { $0 }, ["origin", "reason"])
    }

    func testMenuBarSourcesNeverHardCodeAMachineOrHomePath() throws {
        let sources = try FileManager.default.contentsOfDirectory(at: MenuBarFixtures.menuBarSources, includingPropertiesForKeys: nil)
            .filter { $0.pathExtension == "swift" }

        for file in sources {
            let text = try String(contentsOf: file, encoding: .utf8)

            // "/Users/" catches any hard-coded home path, whoever the user is (ADR-003 D7 row 6).
            for marker in ["/Users/", ".ts.net", "100.64."] {
                XCTAssertFalse(text.contains(marker), "\(file.lastPathComponent) contains \(marker)")
            }
        }
    }

    func testEveryStateIconResolvesOnThisMacOS() {
        let states: [PanelState] = [.loading, .fresh, .stale, .aiSetupRequired, .coreUnreachable, .error(.brief)]

        for state in states {
            XCTAssertNotNil(NSImage(systemSymbolName: state.systemImage, accessibilityDescription: nil), state.systemImage)
        }

        for symbol in ["checkmark.seal", "questionmark.circle", "doc.text", "pause.circle", "play.circle", "safari", "gearshape", "power"] {
            XCTAssertNotNil(NSImage(systemSymbolName: symbol, accessibilityDescription: nil), symbol)
        }
    }

    func testRFC3339DisplayKeepsTheDocumentsOffset() {
        let parsed = RFC3339.parse("2026-10-09T05:30:00+02:00")

        XCTAssertEqual(parsed?.timeZone.secondsFromGMT(), 7200)
        XCTAssertEqual(parsed?.date.timeIntervalSince1970, 1_791_516_600)
        XCTAssertEqual(RFC3339.parse("2026-10-09T03:30:00Z")?.timeZone, .gmt)
        XCTAssertEqual(RFC3339.display("not a date"), "not a date")

        let display = RFC3339.display("2026-10-09T05:30:00+02:00", locale: Locale(identifier: "en_GB"))
        XCTAssertTrue(display.contains("5:30"), "shown in the document's +02:00 offset, not converted: \(display)")
        XCTAssertTrue(display.contains("2026"), display)
    }
}
