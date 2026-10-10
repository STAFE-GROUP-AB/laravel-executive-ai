// Clocks for the installer (S1-05). The event log needs one monotonic-backed wall clock for the
// whole run (install-measurement §4); tests need a clock they can step by hand.

import Foundation

protocol InstallClock: Sendable {
    func now() -> Date
}

/// Wall-clock time anchored once and advanced by `ContinuousClock`, so a run is measured on one
/// clock that cannot jump backwards if the system clock is adjusted mid-install.
struct MonotonicWallClock: InstallClock {
    private let anchorDate: Date
    private let anchorInstant: ContinuousClock.Instant

    init() {
        anchorDate = Date()
        anchorInstant = ContinuousClock.now
    }

    func now() -> Date {
        let elapsed = anchorInstant.duration(to: ContinuousClock.now)
        let seconds = Double(elapsed.components.seconds)
        let attoseconds = Double(elapsed.components.attoseconds) / 1e18

        return anchorDate.addingTimeInterval(seconds + attoseconds)
    }
}

/// A clock that only moves when told to. Used by tests and by the DEBUG simulation.
final class ManualClock: InstallClock, @unchecked Sendable {
    private let lock = NSLock()
    private var current: Date
    private let step: TimeInterval

    /// - Parameters:
    ///   - start: the first value `now()` returns.
    ///   - step: how far the clock advances on every `now()` call after the first (0 to freeze).
    init(start: Date, step: TimeInterval = 0) {
        current = start
        self.step = step
    }

    func now() -> Date {
        lock.lock()
        defer { lock.unlock() }

        let value = current
        current = current.addingTimeInterval(step)

        return value
    }

    func advance(by seconds: TimeInterval) {
        lock.lock()
        defer { lock.unlock() }

        current = current.addingTimeInterval(seconds)
    }
}
