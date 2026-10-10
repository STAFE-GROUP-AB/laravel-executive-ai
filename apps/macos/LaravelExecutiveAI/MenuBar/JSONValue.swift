// A small JSON value tree (S1-09).
//
// The brief document is projected to the parity payload and canonicalised as JSON (brief-parity.md
// §1), so the menu bar needs a representation that keeps JSON's own type distinctions — a number is
// a number, `null` is a value, lists keep their order — without going through NSNumber. Decoding is
// lenient on unknown keys by construction (brief schema §4: readers never crash on a higher minor).

import Foundation

enum JSONValue: Equatable, Sendable {
    case null
    case bool(Bool)
    case number(Double)
    case string(String)
    case array([JSONValue])
    case object([String: JSONValue])

    static func parse(_ data: Data) throws -> JSONValue {
        try JSONDecoder().decode(JSONValue.self, from: data)
    }

    subscript(key: String) -> JSONValue? {
        guard case .object(let members) = self else {
            return nil
        }

        return members[key]
    }

    var stringValue: String? {
        guard case .string(let value) = self else {
            return nil
        }

        return value
    }

    var doubleValue: Double? {
        guard case .number(let value) = self else {
            return nil
        }

        return value
    }

    var intValue: Int? {
        guard let double = doubleValue, double.rounded() == double, abs(double) < 9_007_199_254_740_992 else {
            return nil
        }

        return Int(double)
    }

    var arrayValue: [JSONValue]? {
        guard case .array(let value) = self else {
            return nil
        }

        return value
    }

    var objectValue: [String: JSONValue]? {
        guard case .object(let value) = self else {
            return nil
        }

        return value
    }

    var isNull: Bool {
        self == .null
    }
}

extension JSONValue: Decodable {
    init(from decoder: any Decoder) throws {
        let container = try decoder.singleValueContainer()

        if container.decodeNil() {
            self = .null

            return
        }

        if let bool = try? container.decode(Bool.self) {
            self = .bool(bool)

            return
        }

        if let number = try? container.decode(Double.self) {
            self = .number(number)

            return
        }

        if let string = try? container.decode(String.self) {
            self = .string(string)

            return
        }

        if let array = try? container.decode([JSONValue].self) {
            self = .array(array)

            return
        }

        let object = try container.decode([String: JSONValue].self)
        self = .object(object)
    }
}
