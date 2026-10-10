// RFC 3339 text → a date plus the offset it was written in (S1-09).
//
// The parity payload keeps the document's timestamp text untouched (brief-parity.md §1 "Raw
// timestamps"); this is presentation only, so the panel can show "9 Oct 2026, 05:30" in the
// brief's own offset the way the PWA does (pwa.md §3).

import Foundation

enum RFC3339 {
    struct Parsed: Equatable, Sendable {
        let date: Date
        let timeZone: TimeZone
    }

    static func parse(_ text: String) -> Parsed? {
        guard let date = try? Date(text, strategy: .iso8601) else {
            return nil
        }

        return Parsed(date: date, timeZone: offset(in: text) ?? .gmt)
    }

    static func display(_ text: String, locale: Locale = .autoupdatingCurrent) -> String {
        guard let parsed = parse(text) else {
            return text
        }

        let style = Date.FormatStyle(date: .abbreviated, time: .shortened, locale: locale, timeZone: parsed.timeZone)

        return parsed.date.formatted(style)
    }

    private static func offset(in text: String) -> TimeZone? {
        if text.hasSuffix("Z") {
            return .gmt
        }

        guard let timeStart = text.firstIndex(of: "T") else {
            return nil
        }

        let time = text[timeStart...]

        guard let signIndex = time.lastIndex(where: { $0 == "+" || $0 == "-" }) else {
            return nil
        }

        let parts = time[time.index(after: signIndex)...].split(separator: ":")

        guard parts.count == 2, let hours = Int(parts[0]), let minutes = Int(parts[1]) else {
            return nil
        }

        let seconds = (hours * 3600 + minutes * 60) * (time[signIndex] == "-" ? -1 : 1)

        return TimeZone(secondsFromGMT: seconds)
    }
}
