// Child stdout/stderr capture into `LEA_LOG_DIR` with size-based rotation (ADR-001 D4: logs live
// in ~/Library/Logs/<bundle id>, never backed up).
//
// `<name>.log` is the live file; on exceeding `maxBytes` it becomes `<name>.log.1`, the previous
// `.1` becomes `.2`, and so on up to `keep`. Nothing is ever parsed or redacted here: PHP's own
// log channels carry the ADR-003 Redactor, and FrankenPHP/Caddy write JSON access/error lines
// that contain no credential by construction (the Caddyfile logs at WARN).

import Foundation
import Synchronization

protocol LogSink: Sendable {
    func append(_ data: Data)
}

/// Discards everything. Tests and the DEBUG simulation.
struct DiscardingLogSink: LogSink {
    func append(_ data: Data) {}
}

final class RotatingLogFile: LogSink {
    private struct State {
        var handle: FileHandle?
        var bytes: Int
    }

    let url: URL
    let maxBytes: Int
    let keep: Int
    private let state: Mutex<State>

    init(url: URL, maxBytes: Int = 5 * 1024 * 1024, keep: Int = 5) {
        self.url = url
        self.maxBytes = max(maxBytes, 1)
        self.keep = max(keep, 1)
        state = Mutex(State(handle: nil, bytes: 0))
    }

    func append(_ data: Data) {
        guard !data.isEmpty else {
            return
        }

        state.withLock { state in
            if state.handle == nil {
                open(&state)
            }

            if state.bytes + data.count > maxBytes, state.bytes > 0 {
                rotate(&state)
                open(&state)
            }

            guard let handle = state.handle else {
                return
            }

            do {
                try handle.write(contentsOf: data)
                state.bytes += data.count
            } catch {
                state.handle = nil
            }
        }
    }

    func close() {
        state.withLock { state in
            try? state.handle?.close()
            state.handle = nil
        }
    }

    private func open(_ state: inout State) {
        let fileManager = FileManager.default

        do {
            try fileManager.createDirectory(at: url.deletingLastPathComponent(), withIntermediateDirectories: true)

            if !fileManager.fileExists(atPath: url.path) {
                fileManager.createFile(atPath: url.path, contents: nil, attributes: [.posixPermissions: 0o600])
            }

            let handle = try FileHandle(forWritingTo: url)
            state.bytes = Int(try handle.seekToEnd())
            state.handle = handle
        } catch {
            state.handle = nil
            state.bytes = 0
        }
    }

    private func rotate(_ state: inout State) {
        try? state.handle?.close()
        state.handle = nil
        state.bytes = 0

        let fileManager = FileManager.default

        for index in stride(from: keep, through: 1, by: -1) {
            let older = Self.rotated(url, index: index)
            let newer = index == 1 ? url : Self.rotated(url, index: index - 1)

            if index == keep, fileManager.fileExists(atPath: older.path) {
                try? fileManager.removeItem(at: older)
            }

            if fileManager.fileExists(atPath: newer.path) {
                try? fileManager.moveItem(at: newer, to: older)
            }
        }
    }

    static func rotated(_ url: URL, index: Int) -> URL {
        url.deletingLastPathComponent().appending(path: "\(url.lastPathComponent).\(index)")
    }
}
