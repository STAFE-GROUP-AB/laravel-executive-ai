// The Swift port of BriefParityPayload::fromDocument() (brief-parity.md §1): the export of the
// canonical fixture is byte-identical to the committed PHP snapshot, every fixture projects and
// re-canonicalises stably, and the refusals of the reference are refusals here.

import XCTest
@testable import LaravelExecutiveAI

final class BriefParityPayloadTests: XCTestCase {

    func testCanonicalFixtureExportIsByteIdenticalToThePHPSnapshot() throws {
        let snapshotURL = MenuBarFixtures.repositoryRoot.appending(path: "tests/__snapshots__/brief-canonical.parity.json")
        let expected = try Data(contentsOf: snapshotURL)
        let payload = try BriefParityPayload.fromDocument(try MenuBarFixtures.brief("brief-canonical"))
        // The PHP snapshot test writes `parityJson() . "\n"` (BriefMailSnapshotTest); the file is
        // the payload bytes plus that one trailing newline, nothing else.
        let exported = Data((try payload.canonicalJSON() + "\n").utf8)

        XCTAssertEqual(exported, expected)
        XCTAssertEqual(exported.count, 4794)
        XCTAssertEqual(payload.contentHash, "sha256:558c32969727cb2a55714a4698183318c813445b7288011fc668d08cdb0d5a26")
        XCTAssertEqual(payload.items.count, 5)
        XCTAssertEqual(payload.items.last?.uncertainty?.confidence, 0.4)
        XCTAssertTrue(try payload.canonicalJSON().contains("\"confidence\":0.4"))
    }

    func testEveryBriefFixtureProjectsAndReCanonicalisesStably() throws {
        for name in MenuBarFixtures.briefFixtureNames {
            let document = try MenuBarFixtures.brief(name)
            let payload = try BriefParityPayload.fromDocument(document)
            let canonical = try payload.canonicalJSON()
            let reparsed = try JCS.canonicalize(Data(canonical.utf8))

            XCTAssertEqual(canonical, reparsed, name)
            XCTAssertEqual(payload.schemaVersion, "1.0.0", name)
            XCTAssertEqual(try JSONValue.parse(document)["items"]?.arrayValue?.count, payload.items.count, name)

            for item in payload.items {
                XCTAssertTrue(!item.sourceRefs.isEmpty || item.uncertainty != nil, "\(name) \(item.id) is a bare claim")
            }
        }
    }

    func testKeySetIsFixedAndAbsentOptionalsAreNull() throws {
        let payload = try BriefParityPayload.fromDocument(try MenuBarFixtures.brief("brief-canonical"))
        let json = payload.jsonValue

        XCTAssertEqual(Set(json.objectValue?.keys.map { $0 } ?? []), [
            "schema_version", "id", "version", "generated_at", "period_start", "period_end", "language",
            "subject", "summary", "privacy_mode", "content_hash", "items",
        ])

        let risk = try XCTUnwrap(json["items"]?.arrayValue?[1])

        XCTAssertEqual(Set(risk.objectValue?.keys.map { $0 } ?? []), [
            "id", "kind", "priority", "title", "body", "due_at", "source_refs", "uncertainty", "action_ids",
        ])
        XCTAssertEqual(risk["due_at"], .null)
        XCTAssertEqual(risk["uncertainty"], .null)
        XCTAssertEqual(risk["action_ids"], .array([]))

        let crmRef = try XCTUnwrap(json["items"]?.arrayValue?[0]["source_refs"]?.arrayValue?[1])

        XCTAssertEqual(crmRef["account_label"], .null)
        XCTAssertEqual(crmRef["type"], .string("crm"))
    }

    func testRefusesABareClaim() throws {
        var document = try XCTUnwrap(try JSONValue.parse(try MenuBarFixtures.brief("brief-canonical")).objectValue)
        var items = try XCTUnwrap(document["items"]?.arrayValue)
        var item = try XCTUnwrap(items[0].objectValue)
        item["source_refs"] = .array([])
        item["uncertainty"] = .null
        items[0] = .object(item)
        document["items"] = .array(items)

        XCTAssertThrowsError(try BriefParityPayload.fromDocument(.object(document))) { error in
            XCTAssertEqual(error as? BriefParityError, .bareClaim(itemId: "01M4FJ7HX8XJJ556PHW82CAXMN"))
        }
    }

    func testRefusesAnUnknownSourceReference() throws {
        var document = try XCTUnwrap(try JSONValue.parse(try MenuBarFixtures.brief("brief-canonical")).objectValue)
        var items = try XCTUnwrap(document["items"]?.arrayValue)
        var item = try XCTUnwrap(items[0].objectValue)
        item["source_refs"] = .array([.string("01M4000000000000000000000X")])
        items[0] = .object(item)
        document["items"] = .array(items)

        XCTAssertThrowsError(try BriefParityPayload.fromDocument(.object(document))) { error in
            XCTAssertEqual(error as? BriefParityError, .unknownSource(itemId: "01M4FJ7HX8XJJ556PHW82CAXMN", sourceId: "01M4000000000000000000000X"))
        }
    }

    func testRefusesANewerSchemaMajorAndAcceptsANewerMinor() throws {
        var document = try XCTUnwrap(try JSONValue.parse(try MenuBarFixtures.brief("brief-canonical")).objectValue)
        document["schema_version"] = .string("2.0.0")

        XCTAssertThrowsError(try BriefParityPayload.fromDocument(.object(document))) { error in
            XCTAssertEqual(error as? BriefParityError, .unsupportedSchemaMajor(found: "2.0.0", supported: "1.0.0"))
        }

        document["schema_version"] = .string("1.4.0")
        document["owner_note"] = .string("an optional field a later minor adds")

        XCTAssertEqual(try BriefParityPayload.fromDocument(.object(document)).schemaVersion, "1.4.0")
    }
}
