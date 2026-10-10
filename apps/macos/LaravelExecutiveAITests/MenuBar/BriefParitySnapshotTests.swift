// The menu bar's side of the S1-10 tri-surface brief parity gate (brief-parity.md §3).
//
// Every brief fixture is projected through the Swift port of BriefParityPayload::fromDocument()
// and exported as RFC 8785 bytes plus one trailing newline — the committed snapshot's framing —
// to <export dir>/<fixture>.parity.json, where tests/E2E/BriefParityTest.php (run after this in
// the `parity` CI job, with LXAI_SWIFT_PARITY_EXPORT_DIR pointing at the same directory) compares
// the bytes with the mail and the PWA exports.
//
// The export directory is `build/parity` under the repository root (git-ignored, next to the
// xcresult bundles CI writes), or LXAI_SWIFT_PARITY_EXPORT_DIR when the test process has it.
// xcodebuild's TEST_RUNNER_* handoff does not reach an app-hosted test on macOS — the host is
// launched with a scrubbed environment — so the fixed location is what CI relies on.
//
// Independently of the export, the canonical fixture's bytes are held to the committed PHP
// snapshot and every fixture's `version`, `generated_at` and `content_hash` to the fixture's own,
// so this lane never passes on an absent oracle.

import XCTest
@testable import LaravelExecutiveAI

final class BriefParitySnapshotTests: XCTestCase {
    static let exportDirectoryVariable = "LXAI_SWIFT_PARITY_EXPORT_DIR"
    static let defaultExportPath = "build/parity"
    static let canonicalFixture = "brief-canonical"

    func testEveryFixtureExportsTheParityPayloadWithTheFixturesVersionAndGeneratedAt() throws {
        let exportDirectory = Self.exportDirectory()
        let snapshotURL = MenuBarFixtures.repositoryRoot.appending(path: "tests/__snapshots__/\(Self.canonicalFixture).parity.json")
        let snapshot = try Data(contentsOf: snapshotURL)
        let fixtures = MenuBarFixtures.briefFixtureNames

        XCTAssertFalse(fixtures.isEmpty, "no brief fixtures under packaging/fixtures/briefs")
        XCTAssertTrue(fixtures.contains(Self.canonicalFixture), "the canonical fixture is missing from the pack")

        try FileManager.default.createDirectory(at: exportDirectory, withIntermediateDirectories: true)

        for stale in try FileManager.default.contentsOfDirectory(atPath: exportDirectory.path) where stale.hasSuffix(".parity.json") {
            try FileManager.default.removeItem(at: exportDirectory.appending(path: stale))
        }

        for name in fixtures {
            let document = try JSONValue.parse(try MenuBarFixtures.brief(name))
            let payload = try BriefParityPayload.fromDocument(document)
            let export = Data((try payload.canonicalJSON() + "\n").utf8)

            XCTAssertEqual(payload.version, document["version"]?.intValue, "\(name): version differs from the fixture")
            XCTAssertEqual(payload.generatedAt, document["generated_at"]?.stringValue, "\(name): generated_at differs from the fixture")
            XCTAssertEqual(payload.contentHash, document["content_hash"]?.stringValue, "\(name): content_hash differs from the fixture")
            XCTAssertEqual(export.last, UInt8(ascii: "\n"), "\(name): export must end with exactly one newline")

            if name == Self.canonicalFixture {
                XCTAssertEqual(export, snapshot, "\(name): Swift export differs from tests/__snapshots__/\(name).parity.json")
            }

            let file = exportDirectory.appending(path: "\(name).parity.json")
            try export.write(to: file, options: .atomic)

            XCTAssertEqual(try Data(contentsOf: file), export, "\(name): export did not round-trip through \(file.path)")
        }

        let written = try FileManager.default.contentsOfDirectory(atPath: exportDirectory.path)
            .filter { $0.hasSuffix(".parity.json") }
            .map { String($0.dropLast(".parity.json".count)) }
            .sorted()

        XCTAssertEqual(written, fixtures, "\(exportDirectory.path) must hold exactly one export per fixture")
    }

    private static func exportDirectory() -> URL {
        if let path = ProcessInfo.processInfo.environment[exportDirectoryVariable],
           !path.trimmingCharacters(in: .whitespaces).isEmpty {
            return URL(fileURLWithPath: path, isDirectory: true)
        }

        return MenuBarFixtures.repositoryRoot.appending(path: defaultExportPath, directoryHint: .isDirectory)
    }
}
