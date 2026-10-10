// RFC 8785 (JSON Canonicalization Scheme) for the value types the fixture schemas allow (S1-09).
//
// Port of packaging/fixtures/lib/jcs.mjs and App\Support\Json\Jcs; the three must agree byte for
// byte, which MenuBar/JCSTests and BriefParityPayloadTests prove against the frozen fixtures and
// tests/__snapshots__/brief-canonical.parity.json.
//
// Rules: object members sorted by UTF-16 code units, no whitespace, strings escaped as
// JSON.stringify does (short escapes, lowercase \u00xx for other control characters, no escaping
// of "/" or non-ASCII, U+2028/2029 literal), numbers printed with the ES6 Number::toString
// algorithm (shortest round-trip digits; integral doubles without a fraction; exponent form only
// for magnitudes >= 1e21 or < 1e-6).

import Foundation

enum JCS {
    enum Error: Swift.Error, Equatable {
        case nonFiniteNumber
    }

    static func canonicalize(_ value: JSONValue) throws -> String {
        switch value {
        case .null:
            return "null"
        case .bool(let bool):
            return bool ? "true" : "false"
        case .number(let value):
            return try number(value)
        case .string(let value):
            return string(value)
        case .array(let elements):
            return "[" + (try elements.map(canonicalize).joined(separator: ",")) + "]"
        case .object(let members):
            let keys = members.keys.sorted { Array($0.utf16).lexicographicallyPrecedes(Array($1.utf16)) }

            return "{" + (try keys.map { "\(string($0)):\(try canonicalize(members[$0]!))" }.joined(separator: ",")) + "}"
        }
    }

    static func canonicalize(_ data: Data) throws -> String {
        try canonicalize(JSONValue.parse(data))
    }

    static func string(_ value: String) -> String {
        var out = "\""

        for scalar in value.unicodeScalars {
            switch scalar {
            case "\"":
                out += "\\\""
            case "\\":
                out += "\\\\"
            case "\u{08}":
                out += "\\b"
            case "\u{0C}":
                out += "\\f"
            case "\n":
                out += "\\n"
            case "\r":
                out += "\\r"
            case "\t":
                out += "\\t"
            case _ where scalar.value < 0x20:
                out += String(format: "\\u%04x", scalar.value)
            default:
                out.unicodeScalars.append(scalar)
            }
        }

        return out + "\""
    }

    /// ES6 Number::toString for finite doubles (RFC 8785 §3.2.2.3).
    static func number(_ value: Double) throws -> String {
        guard value.isFinite else {
            throw Error.nonFiniteNumber
        }

        if value == 0 {
            return "0"
        }

        let sign = value < 0 ? "-" : ""
        let (digits, n) = shortestDigits(abs(value))
        let k = digits.count

        if k <= n, n <= 21 {
            return sign + digits + String(repeating: "0", count: n - k)
        }

        if n > 0, n <= 21 {
            return sign + digits.prefix(n) + "." + digits.dropFirst(n)
        }

        if n > -6, n <= 0 {
            return sign + "0." + String(repeating: "0", count: -n) + digits
        }

        let exponent = n - 1
        let exponentSign = exponent < 0 ? "-" : "+"
        let mantissa = k == 1 ? digits : String(digits.prefix(1)) + "." + digits.dropFirst(1)

        return sign + mantissa + "e" + exponentSign + String(abs(exponent))
    }

    /// Shortest round-trip decimal digits of a positive double and the position n of the decimal
    /// point such that value = 0.<digits> x 10^n. Swift's `description` already yields the shortest
    /// round-trip digits; only the layout differs from ES6.
    private static func shortestDigits(_ value: Double) -> (String, Int) {
        var repr = value.description
        var exponent = 0

        if let e = repr.firstIndex(where: { $0 == "e" || $0 == "E" }) {
            exponent = Int(repr[repr.index(after: e)...].replacingOccurrences(of: "+", with: "")) ?? 0
            repr = String(repr[..<e])
        }

        let parts = repr.split(separator: ".", omittingEmptySubsequences: false)
        let integerPart = String(parts[0])
        let fractionPart = parts.count > 1 ? String(parts[1]) : ""
        let all = integerPart + fractionPart
        let stripped = all.drop(while: { $0 == "0" })
        let leadingZeros = all.count - stripped.count
        let n = integerPart.count - leadingZeros + exponent
        var digits = String(stripped)

        while digits.hasSuffix("0") {
            digits.removeLast()
        }

        return (digits, n)
    }
}
