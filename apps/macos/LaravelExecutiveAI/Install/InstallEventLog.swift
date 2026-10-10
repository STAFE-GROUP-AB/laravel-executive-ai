// Install event log (S1-05) — the contract in docs/operations/install-measurement.md §4.
//
// One NDJSON line per event, exactly `{"state","at"}` for state entries and prompts, and
// `{"state","marker","outcome","at"}` for async steps, so that
// `node packaging/manifest/install-timer/install-timer.mjs record --events <file>` consumes the
// file unchanged. The API only accepts enum values: there is no way to write a token, a name, an
// e-mail address or mail content into this log.

import Foundation

struct InstallEvent: Equatable, Sendable {
    enum Kind: Equatable, Sendable {
        case stateEntry(InstallState)
        case humanInteractionStart
        case humanInteractionEnd
        case asyncStart(InstallAsyncStep)
        case asyncEnd(InstallAsyncStep, InstallAsyncOutcome)
    }

    let kind: Kind
    let at: Date

    /// The `state` field as the timer reads it.
    var stateName: String {
        switch kind {
        case .stateEntry(let state): return state.rawValue
        case .humanInteractionStart: return "human_interaction_start"
        case .humanInteractionEnd: return "human_interaction_end"
        case .asyncStart(let step), .asyncEnd(let step, _): return step.rawValue
        }
    }

    func line(timestamp: String) -> String {
        switch kind {
        case .stateEntry, .humanInteractionStart, .humanInteractionEnd:
            return "{\"state\":\"\(stateName)\",\"at\":\"\(timestamp)\"}"
        case .asyncStart:
            return "{\"state\":\"\(stateName)\",\"marker\":\"start\",\"at\":\"\(timestamp)\"}"
        case .asyncEnd(_, let outcome):
            return "{\"state\":\"\(stateName)\",\"marker\":\"end\",\"outcome\":\"\(outcome.rawValue)\",\"at\":\"\(timestamp)\"}"
        }
    }
}

@MainActor
final class InstallEventLog {
    private(set) var events: [InstallEvent] = []
    private(set) var lines: [String] = []
    let fileURL: URL?

    private let clock: any InstallClock
    private let formatter: DateFormatter
    private var handle: FileHandle?
    private var promptOpen = false

    /// - Parameters:
    ///   - fileURL: where the NDJSON lines are appended; nil keeps the log in memory only.
    ///   - clock: the one clock for the whole run (install-measurement §4).
    ///   - timeZone: the zone the RFC 3339 offset is written in.
    init(fileURL: URL?, clock: any InstallClock, timeZone: TimeZone = .current) {
        self.fileURL = fileURL
        self.clock = clock
        formatter = Self.makeFormatter(timeZone: timeZone)
    }

    // MARK: - Recording

    /// Entry into one of the nine core states. Optional states are async steps, never entries.
    func enter(_ state: InstallState) {
        precondition(state.isCore, "optional states are logged as async steps")

        append(InstallEvent(kind: .stateEntry(state), at: clock.now()))
    }

    /// A screen starts waiting for the person. No nesting: a second start while one is open is
    /// ignored so the pair structure the timer requires can never be broken by a UI glitch.
    func humanInteractionStart() {
        guard !promptOpen else {
            return
        }

        promptOpen = true
        append(InstallEvent(kind: .humanInteractionStart, at: clock.now()))
    }

    /// The person's input has been accepted and the installer proceeds.
    func humanInteractionEnd() {
        guard promptOpen else {
            return
        }

        promptOpen = false
        append(InstallEvent(kind: .humanInteractionEnd, at: clock.now()))
    }

    var isWaitingForPerson: Bool {
        promptOpen
    }

    func asyncStart(_ step: InstallAsyncStep) {
        append(InstallEvent(kind: .asyncStart(step), at: clock.now()))
    }

    func asyncEnd(_ step: InstallAsyncStep, outcome: InstallAsyncOutcome) {
        append(InstallEvent(kind: .asyncEnd(step, outcome), at: clock.now()))
    }

    // MARK: - Reading

    /// The whole log as the timer reads it (one JSON object per line, trailing newline).
    var ndjson: String {
        lines.map { $0 + "\n" }.joined()
    }

    /// The core states entered so far, in order.
    var enteredStates: [InstallState] {
        events.compactMap { event in
            if case .stateEntry(let state) = event.kind {
                return state
            }

            return nil
        }
    }

    // MARK: - Formatting

    /// RFC 3339 with milliseconds, e.g. `2026-10-10T09:00:04.200+02:00` (`Z` for UTC).
    static func rfc3339(_ date: Date, timeZone: TimeZone) -> String {
        makeFormatter(timeZone: timeZone).string(from: date)
    }

    private static func makeFormatter(timeZone: TimeZone) -> DateFormatter {
        let formatter = DateFormatter()
        formatter.locale = Locale(identifier: "en_US_POSIX")
        formatter.calendar = Calendar(identifier: .gregorian)
        formatter.timeZone = timeZone
        formatter.dateFormat = "yyyy-MM-dd'T'HH:mm:ss.SSSXXXXX"

        return formatter
    }

    private func append(_ event: InstallEvent) {
        let line = event.line(timestamp: formatter.string(from: event.at))
        events.append(event)
        lines.append(line)
        write(line)
    }

    private func write(_ line: String) {
        guard let fileURL else {
            return
        }

        if handle == nil {
            let directory = fileURL.deletingLastPathComponent()
            try? FileManager.default.createDirectory(at: directory, withIntermediateDirectories: true)

            if !FileManager.default.fileExists(atPath: fileURL.path) {
                FileManager.default.createFile(atPath: fileURL.path, contents: nil, attributes: [.posixPermissions: 0o600])
            }

            handle = try? FileHandle(forWritingTo: fileURL)
            _ = try? handle?.seekToEnd()
        }

        guard let data = (line + "\n").data(using: .utf8) else {
            return
        }

        try? handle?.write(contentsOf: data)
    }
}
