// Every health fixture decodes into the fields the §7 mapping needs, and the bridge to the
// installer's HealthReadiness agrees with it.

import XCTest
@testable import LaravelExecutiveAI

final class HealthDocumentTests: XCTestCase {

    func testDecodesEveryReadyFixture() throws {
        for fixture in HealthFixture.allCases {
            let document = try MenuBarFixtures.health(fixture)

            XCTAssertEqual(document.schemaVersion, "1.0.0", fixture.rawValue)
            XCTAssertEqual(document.checks.count, 7, fixture.rawValue)
            XCTAssertEqual(document.ready, document.checks.allSatisfy(\.isOk), fixture.rawValue)
            XCTAssertEqual(document.aiReady, document.aiState == .ready, fixture.rawValue)
            XCTAssertEqual(document.latestBrief == nil, document.briefState == .none, fixture.rawValue)
            XCTAssertNil(document.aiActions, fixture.rawValue)
        }
    }

    func testFixtureFieldsMatchTheContractExamples() throws {
        let aiReady = try MenuBarFixtures.health(.aiReady)
        XCTAssertEqual(aiReady.pendingApprovals, 2)
        XCTAssertEqual(aiReady.latestBrief?.etag, "\"01M4FJ7GY0PZH6YA3D9574XN5K:3\"")
        XCTAssertEqual(aiReady.aiProvider, "ollama")

        let noAI = try MenuBarFixtures.health(.coreReadyNoAI)
        XCTAssertFalse(noAI.aiReady)
        XCTAssertEqual(noAI.aiState, .notConfigured)
        XCTAssertNil(noAI.aiProvider)

        let stale = try MenuBarFixtures.health(.staleBriefOffline)
        XCTAssertEqual(stale.briefState, .stale)
        XCTAssertEqual(stale.connectivity, .offline)

        let failing = try MenuBarFixtures.health(.notReadySchedulerFailing)
        XCTAssertFalse(failing.ready)
        XCTAssertEqual(failing.failingChecks.map(\.name), ["scheduler_selftest"])
        XCTAssertEqual(failing.briefState, .error)
        XCTAssertEqual(failing.privacyMode, "EU_RESTRICTED")
        XCTAssertEqual(failing.readiness, HealthReadiness(ready: false, failingChecks: ["scheduler_selftest"], aiState: "configured_untested"))
    }

    func testUnknownEnumValuesAndKeysDecodeLeniently() throws {
        var json = try XCTUnwrap(try JSONValue.parse(try Data(contentsOf: MenuBarFixtures.root.appending(path: "health/ready-ai-ready.json"))).objectValue)
        json["ai_state"] = .string("dreaming")
        json["connectivity"] = .string("tunnelled")
        json["ai_actions"] = .string("paused")
        json["a_field_from_1_1_0"] = .number(1)

        let document = try HealthDocument.decode(try JSONEncoder().encode(JSONValue.object(json)))

        XCTAssertEqual(document.aiState, .unknown)
        XCTAssertEqual(document.connectivity, .unknown)
        XCTAssertEqual(document.aiActions, .paused)
    }
}
