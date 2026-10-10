// Bounded exponential backoff (ADR-001 D3: "1, 2, 4, 8, 16, 30 s capped at 5 restarts per
// 10 minutes, after which it … stops retrying until the user acts").

import Foundation

struct RestartPolicy: Equatable, Sendable {
    /// Delay before the n-th restart inside the window; the last value repeats.
    var delays: [Duration]
    /// Restarts allowed inside `window` before the service is marked `failed`.
    var maxRestarts: Int
    var window: Duration

    static let adr001 = RestartPolicy(
        delays: [.seconds(1), .seconds(2), .seconds(4), .seconds(8), .seconds(16), .seconds(30)],
        maxRestarts: 5,
        window: .seconds(600)
    )

    init(delays: [Duration], maxRestarts: Int, window: Duration) {
        precondition(!delays.isEmpty)
        precondition(maxRestarts > 0)
        self.delays = delays
        self.maxRestarts = maxRestarts
        self.window = window
    }

    /// `attempt` is 1-based: the first restart waits `delays[0]`.
    func delay(forAttempt attempt: Int) -> Duration {
        delays[min(max(attempt, 1), delays.count) - 1]
    }

    var windowSeconds: TimeInterval {
        let components = window.components
        return TimeInterval(components.seconds) + TimeInterval(components.attoseconds) / 1e18
    }
}

/// The restart instants of one service, pruned to the policy window.
struct RestartBudget: Equatable, Sendable {
    private(set) var instants: [Date] = []

    mutating func prune(now: Date, policy: RestartPolicy) {
        let cutoff = now.addingTimeInterval(-policy.windowSeconds)
        instants.removeAll { $0 <= cutoff }
    }

    mutating func record(now: Date) {
        instants.append(now)
    }

    mutating func reset() {
        instants.removeAll()
    }

    func count(now: Date, policy: RestartPolicy) -> Int {
        let cutoff = now.addingTimeInterval(-policy.windowSeconds)
        return instants.filter { $0 > cutoff }.count
    }

    /// True when one more restart would exceed `maxRestarts` inside the window.
    func isExhausted(now: Date, policy: RestartPolicy) -> Bool {
        count(now: now, policy: policy) >= policy.maxRestarts
    }
}
