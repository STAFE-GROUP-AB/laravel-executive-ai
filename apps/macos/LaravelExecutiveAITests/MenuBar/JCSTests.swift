// The Swift JCS port must agree byte for byte with packaging/fixtures/lib/jcs.mjs and
// App\Support\Json\Jcs (tests/Unit/Support/JcsTest.php carries the same table).

import XCTest
@testable import LaravelExecutiveAI

final class JCSTests: XCTestCase {

    func testPrintsNumbersTheWayES6NumberToStringDoes() throws {
        let table: [(Double, String)] = [
            (0.4, "0.4"),
            (1.0, "1"),
            (100.0, "100"),
            (1e21, "1e+21"),
            (1e22, "1e+22"),
            (0.000001, "0.000001"),
            (0.0000001, "1e-7"),
            (123456789.125, "123456789.125"),
            (-0.5, "-0.5"),
            (1.5e-10, "1.5e-10"),
            (1e16, "10000000000000000"),
            (0.0, "0"),
            (-0.0, "0"),
            (42, "42"),
            (0.25, "0.25"),
            (0.35, "0.35"),
            (3.0, "3"),
        ]

        for (value, expected) in table {
            XCTAssertEqual(try JCS.number(value), expected, "\(value)")
        }
    }

    func testRefusesNonFiniteNumbers() {
        XCTAssertThrowsError(try JCS.number(.nan))
        XCTAssertThrowsError(try JCS.number(.infinity))
    }

    func testSortsMembersByUTF16CodeUnitsEscapesControlCharactersAndLeavesNonASCIIUnescaped() throws {
        let lineSeparator = "\u{2028}"
        let emoji = "\u{1F600}"
        let value: JSONValue = .object([
            "b": .number(1),
            "a": .array([.bool(true), .null, .string("x/\(lineSeparator)\(emoji)\"\u{01}")]),
            "é": .array([]),
            "Z": .object([:]),
        ])

        XCTAssertEqual(
            try JCS.canonicalize(value),
            "{\"Z\":{},\"a\":[true,null,\"x/\(lineSeparator)\(emoji)\\\"\\u0001\"],\"b\":1,\"é\":[]}"
        )
    }

    func testSortsSupplementaryPlaneKeysByUTF16NotByScalar() throws {
        // U+1F600 is 0xD83D 0xDE00 in UTF-16, which sorts before U+FF21 (0xFF21); by scalar it would
        // sort after. RFC 8785 §3.2.3 requires UTF-16 order, like Array.prototype.sort in JS.
        let value: JSONValue = .object(["\u{FF21}": .number(1), "\u{1F600}": .number(2)])

        XCTAssertEqual(try JCS.canonicalize(value), "{\"\u{1F600}\":2,\"\u{FF21}\":1}")
    }

    func testShortEscapesMatchJSONStringify() {
        XCTAssertEqual(JCS.string("\u{08}\u{0C}\n\r\t\\\"/"), "\"\\b\\f\\n\\r\\t\\\\\\\"/\"")
        XCTAssertEqual(JCS.string("\u{1F}\u{7F}"), "\"\\u001f\u{7F}\"")
    }

    func testCanonicalisationOfEveryBriefFixtureIsStable() throws {
        for name in MenuBarFixtures.briefFixtureNames {
            let first = try JCS.canonicalize(try MenuBarFixtures.brief(name))
            let second = try JCS.canonicalize(Data(first.utf8))

            XCTAssertEqual(first, second, name)
            XCTAssertFalse(first.contains("\n"), name)
        }
    }
}
