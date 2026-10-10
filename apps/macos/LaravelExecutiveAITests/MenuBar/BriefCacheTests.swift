// The on-disk brief cache: one document + ETag, 0600 in a 0700 directory, round-trips, clears,
// and never holds a token.

import XCTest
@testable import LaravelExecutiveAI

final class BriefCacheTests: XCTestCase {

    func testStoresOneDocumentWithItsETagAtMode0600() throws {
        let directory = MenuBarFixtures.temporaryDirectory().appending(path: "menubar")
        let cache = BriefCache(directory: directory)
        let document = try JSONValue.parse(try MenuBarFixtures.brief("brief-canonical"))

        XCTAssertNil(cache.load())

        try cache.store(document: document, etag: "\"01M4FJ7GY0PZH6YA3D9574XN5K:1\"", storedAt: Date(timeIntervalSince1970: 1_791_648_005))

        let loaded = try XCTUnwrap(cache.load())
        XCTAssertEqual(loaded.etag, "\"01M4FJ7GY0PZH6YA3D9574XN5K:1\"")
        XCTAssertEqual(loaded.document, document)
        XCTAssertEqual(loaded.storedAt, "2026-10-10T16:00:05Z")

        let fileAttributes = try FileManager.default.attributesOfItem(atPath: cache.fileURL.path)
        XCTAssertEqual((fileAttributes[.posixPermissions] as? Int), 0o600)

        let directoryAttributes = try FileManager.default.attributesOfItem(atPath: directory.path)
        XCTAssertEqual((directoryAttributes[.posixPermissions] as? Int), 0o700)

        let excluded = try cache.fileURL.resourceValues(forKeys: [.isExcludedFromBackupKey]).isExcludedFromBackup
        XCTAssertEqual(excluded, true)
    }

    func testANewerVersionReplacesTheOnlyEntry() throws {
        let cache = BriefCache(directory: MenuBarFixtures.temporaryDirectory())
        let canonical = try JSONValue.parse(try MenuBarFixtures.brief("brief-canonical"))
        let stale = try JSONValue.parse(try MenuBarFixtures.brief("brief-stale"))

        try cache.store(document: canonical, etag: "\"a:1\"")
        try cache.store(document: stale, etag: "\"b:1\"")

        XCTAssertEqual(cache.load()?.etag, "\"b:1\"")
        XCTAssertEqual(cache.load()?.document, stale)
        XCTAssertEqual(try FileManager.default.contentsOfDirectory(atPath: cache.fileURL.deletingLastPathComponent().path), [BriefCache.fileName])
    }

    func testClearRemovesTheFileAndIsIdempotent() throws {
        let cache = BriefCache(directory: MenuBarFixtures.temporaryDirectory())
        try cache.store(document: try JSONValue.parse(try MenuBarFixtures.brief("brief-canonical")), etag: "\"a:1\"")

        try cache.clear()
        try cache.clear()

        XCTAssertNil(cache.load())
        XCTAssertFalse(FileManager.default.fileExists(atPath: cache.fileURL.path))
    }

    func testACorruptOrForeignFileIsIgnored() throws {
        let cache = BriefCache(directory: MenuBarFixtures.temporaryDirectory())
        try FileManager.default.createDirectory(at: cache.fileURL.deletingLastPathComponent(), withIntermediateDirectories: true)
        try Data("{not json".utf8).write(to: cache.fileURL)

        XCTAssertNil(cache.load())

        try Data("{\"schema\":99,\"etag\":\"x\",\"stored_at\":\"\",\"document\":{}}".utf8).write(to: cache.fileURL)

        XCTAssertNil(cache.load())
    }

    func testDefaultLocationIsTheBundleCachesDirectory() {
        let cache = BriefCache.default(bundleIdentifier: "se.stafe.executive-ai.app")

        XCTAssertTrue(cache.fileURL.path.hasSuffix("/Library/Caches/se.stafe.executive-ai.app/menubar/latest-brief.json"))
    }

    @MainActor
    func testCacheFileWrittenByARefreshNeverContainsATokenOrAHealthBody() async throws {
        let directory = MenuBarFixtures.temporaryDirectory()
        let (model, _, _) = makePanelModel(health: .aiReady, cacheDirectory: directory)

        await model.refresh()

        let bytes = try String(contentsOf: model.cache.fileURL, encoding: .utf8)

        for forbidden in ["lxai1.", "Bearer", "signing_key", "Authorization", "pending_approvals", "X-Lxai"] {
            XCTAssertFalse(bytes.contains(forbidden), "cache file contains \(forbidden)")
        }

        XCTAssertTrue(bytes.contains("\"content_hash\""))
    }
}
