// The semantic projection of one brief document — the Swift port of
// App\Briefs\Render\BriefParityPayload::fromDocument() (brief-parity.md §1, brief schema §6).
//
// The menu bar renders *from* this payload, never from the raw document, and exports it as JCS for
// the S1-10 tri-surface comparison. The shape is fixed: every key is always present, absent
// optionals are null, lists are always lists, item and source order are the document's, timestamps
// keep the document's RFC 3339 text. Changing a key here is a contract change for all three
// surfaces.

import Foundation

struct BriefParityPayload: Equatable, Sendable {
    /// Brief schema §4: the maximum `schema_version` this renderer supports. A document with a
    /// higher major is refused ("Update required"), never rendered partially.
    static let supportedSchemaVersion = "1.0.0"

    struct SourceRef: Equatable, Sendable {
        let id: String
        let type: String
        let accountLabel: String?
        let title: String?
        let uri: String
    }

    struct Uncertainty: Equatable, Sendable {
        let reason: String
        let confidence: Double
    }

    struct Item: Equatable, Sendable {
        let id: String
        let kind: String
        let priority: String
        let title: String
        let body: String
        let dueAt: String?
        let sourceRefs: [SourceRef]
        let uncertainty: Uncertainty?
        let actionIds: [String]
    }

    let schemaVersion: String
    let id: String
    let version: Int
    let generatedAt: String
    let periodStart: String
    let periodEnd: String
    let language: String
    let subject: String
    let summary: String
    let privacyMode: String
    let contentHash: String
    let items: [Item]

    static func fromDocument(_ data: Data) throws -> BriefParityPayload {
        try fromDocument(JSONValue.parse(data))
    }

    static func fromDocument(_ document: JSONValue) throws -> BriefParityPayload {
        let schemaVersion = try string(document, "schema_version")

        guard let major = schemaVersion.split(separator: ".").first.flatMap({ Int($0) }),
              let supportedMajor = supportedSchemaVersion.split(separator: ".").first.flatMap({ Int($0) }) else {
            throw BriefParityError.invalidSchemaVersion(schemaVersion)
        }

        if major > supportedMajor {
            throw BriefParityError.unsupportedSchemaMajor(found: schemaVersion, supported: supportedSchemaVersion)
        }

        var sourcesById: [String: JSONValue] = [:]

        for source in document["sources"]?.arrayValue ?? [] {
            sourcesById[try string(source, "id")] = source
        }

        let items = try (document["items"]?.arrayValue ?? []).map { try item($0, sourcesById: sourcesById) }

        return BriefParityPayload(
            schemaVersion: schemaVersion,
            id: try string(document, "id"),
            version: try int(document, "version"),
            generatedAt: try string(document, "generated_at"),
            periodStart: try string(document, "period_start"),
            periodEnd: try string(document, "period_end"),
            language: try string(document, "language"),
            subject: try string(document, "subject"),
            summary: try string(document, "summary"),
            privacyMode: document["model_provenance"]?["privacy_mode"]?.stringValue ?? "",
            contentHash: try string(document, "content_hash"),
            items: items
        )
    }

    /// The payload as a JSON value tree with the exact key set of the PHP reference.
    var jsonValue: JSONValue {
        .object([
            "schema_version": .string(schemaVersion),
            "id": .string(id),
            "version": .number(Double(version)),
            "generated_at": .string(generatedAt),
            "period_start": .string(periodStart),
            "period_end": .string(periodEnd),
            "language": .string(language),
            "subject": .string(subject),
            "summary": .string(summary),
            "privacy_mode": .string(privacyMode),
            "content_hash": .string(contentHash),
            "items": .array(items.map(\.jsonValue)),
        ])
    }

    /// RFC 8785 canonical JSON — the bytes S1-10 compares across the three surfaces.
    func canonicalJSON() throws -> String {
        try JCS.canonicalize(jsonValue)
    }

    private static func item(_ item: JSONValue, sourcesById: [String: JSONValue]) throws -> Item {
        let itemId = try string(item, "id")
        var sourceRefs: [SourceRef] = []

        for ref in item["source_refs"]?.arrayValue ?? [] {
            guard let sourceId = ref.stringValue else {
                throw BriefParityError.missingField("items[\(itemId)].source_refs")
            }

            guard let source = sourcesById[sourceId] else {
                throw BriefParityError.unknownSource(itemId: itemId, sourceId: sourceId)
            }

            sourceRefs.append(SourceRef(
                id: try string(source, "id"),
                type: try string(source, "type"),
                accountLabel: source["account_label"]?.stringValue,
                title: source["title"]?.stringValue,
                uri: try string(source, "uri")
            ))
        }

        var uncertainty: Uncertainty?

        if let marker = item["uncertainty"], marker.objectValue != nil {
            guard let confidence = marker["confidence"]?.doubleValue else {
                throw BriefParityError.missingField("items[\(itemId)].uncertainty.confidence")
            }

            uncertainty = Uncertainty(reason: try string(marker, "reason"), confidence: confidence)
        }

        if sourceRefs.isEmpty, uncertainty == nil {
            throw BriefParityError.bareClaim(itemId: itemId)
        }

        return Item(
            id: itemId,
            kind: try string(item, "kind"),
            priority: try string(item, "priority"),
            title: try string(item, "title"),
            body: try string(item, "body"),
            dueAt: item["due_at"]?.stringValue,
            sourceRefs: sourceRefs,
            uncertainty: uncertainty,
            actionIds: (item["action_ids"]?.arrayValue ?? []).compactMap(\.stringValue)
        )
    }

    private static func string(_ value: JSONValue, _ key: String) throws -> String {
        guard let string = value[key]?.stringValue else {
            throw BriefParityError.missingField(key)
        }

        return string
    }

    private static func int(_ value: JSONValue, _ key: String) throws -> Int {
        guard let int = value[key]?.intValue else {
            throw BriefParityError.missingField(key)
        }

        return int
    }
}

extension BriefParityPayload.Item {
    var jsonValue: JSONValue {
        .object([
            "id": .string(id),
            "kind": .string(kind),
            "priority": .string(priority),
            "title": .string(title),
            "body": .string(body),
            "due_at": dueAt.map(JSONValue.string) ?? .null,
            "source_refs": .array(sourceRefs.map(\.jsonValue)),
            "uncertainty": uncertainty.map { .object(["reason": .string($0.reason), "confidence": .number($0.confidence)]) } ?? .null,
            "action_ids": .array(actionIds.map(JSONValue.string)),
        ])
    }
}

extension BriefParityPayload.SourceRef {
    var jsonValue: JSONValue {
        .object([
            "id": .string(id),
            "type": .string(type),
            "account_label": accountLabel.map(JSONValue.string) ?? .null,
            "title": title.map(JSONValue.string) ?? .null,
            "uri": .string(uri),
        ])
    }
}

enum BriefParityError: Error, Equatable, Sendable {
    case missingField(String)
    case invalidSchemaVersion(String)
    /// Brief schema §4: the document's major is above `supportedSchemaVersion` → "Update required".
    case unsupportedSchemaMajor(found: String, supported: String)
    case unknownSource(itemId: String, sourceId: String)
    /// Brief schema §4.2: an item with neither `source_refs` nor an `uncertainty` marker.
    case bareClaim(itemId: String)
}
