// The last good brief document and its ETag on disk (S1-09; ipc-contract §6.2, blueprint §14).
//
// Opening the panel reads only this file, so the panel opens instantly and never generates or
// fetches on open. Policy (docs/architecture/menubar.md §3): exactly one document — the latest
// frozen version — plus its ETag; a newer version replaces it; the file is mode 0600 in a 0700
// directory under ~/Library/Caches/<bundle id>/menubar/, excluded from backups, and it holds the
// brief document only: no token, no pairing grant, no health body.

import Foundation

struct CachedBrief: Equatable, Sendable {
    let etag: String
    let storedAt: String
    let document: JSONValue
}

struct BriefCache: Sendable {
    static let fileName = "latest-brief.json"

    let fileURL: URL

    init(fileURL: URL) {
        self.fileURL = fileURL
    }

    init(directory: URL) {
        fileURL = directory.appending(path: BriefCache.fileName)
    }

    /// `~/Library/Caches/<bundle id>/menubar/latest-brief.json` — rooted at `InstallPaths.caches`
    /// (ADR-001 D4) so the installer, uninstall and "Reset security pairing" share one definition
    /// of where the GUI's caches live.
    static func `default`(bundleIdentifier: String, fileManager: FileManager = .default) -> BriefCache {
        BriefCache(paths: InstallPaths.default(bundleIdentifier: bundleIdentifier, fileManager: fileManager))
    }

    init(paths: InstallPaths) {
        self.init(directory: paths.caches.appending(path: "menubar"))
    }

    func load() -> CachedBrief? {
        guard let data = try? Data(contentsOf: fileURL),
              let file = try? JSONDecoder().decode(CacheFile.self, from: data),
              file.schema == CacheFile.currentSchema else {
            return nil
        }

        return CachedBrief(etag: file.etag, storedAt: file.storedAt, document: file.document)
    }

    func store(document: JSONValue, etag: String, storedAt: Date = Date()) throws {
        let fileManager = FileManager.default
        let directory = fileURL.deletingLastPathComponent()

        try fileManager.createDirectory(
            at: directory,
            withIntermediateDirectories: true,
            attributes: [.posixPermissions: 0o700]
        )

        let file = CacheFile(
            schema: CacheFile.currentSchema,
            etag: etag,
            storedAt: storedAt.formatted(.iso8601),
            document: document
        )
        let data = try JSONEncoder().encode(file)

        try data.write(to: fileURL, options: [.atomic])
        try fileManager.setAttributes([.posixPermissions: 0o600], ofItemAtPath: fileURL.path)

        var url = fileURL
        var values = URLResourceValues()
        values.isExcludedFromBackup = true
        try url.setResourceValues(values)
    }

    /// Invalidation: "Reset security pairing" and uninstall call this; so does a refused document.
    func clear() throws {
        guard FileManager.default.fileExists(atPath: fileURL.path) else {
            return
        }

        try FileManager.default.removeItem(at: fileURL)
    }

    private struct CacheFile: Codable {
        static let currentSchema = 1

        let schema: Int
        let etag: String
        let storedAt: String
        let document: JSONValue
    }
}

extension JSONValue: Encodable {
    func encode(to encoder: any Encoder) throws {
        var container = encoder.singleValueContainer()

        switch self {
        case .null:
            try container.encodeNil()
        case .bool(let bool):
            try container.encode(bool)
        case .number(let number):
            try container.encode(number)
        case .string(let string):
            try container.encode(string)
        case .array(let array):
            try container.encode(array)
        case .object(let object):
            try container.encode(object)
        }
    }
}
