// Unified logging for the Supervisor (ADR-003 D7 row 6, threat model T-39): every interpolated
// value is `.private` — the default for non-literals — so a secret, a path with a user name or a
// peer detail never appears in `log show` output without the private-data override. Only string
// literals are `.public`.

import Foundation
import os

enum SupervisorLog {
    nonisolated(unsafe) private static var subsystem = SupervisorIdentity.defaultBundlePrefix + ".supervisor"

    static func configure(subsystem: String) {
        self.subsystem = subsystem
    }

    static var boot: Logger { Logger(subsystem: subsystem, category: "boot") }
    static var services: Logger { Logger(subsystem: subsystem, category: "services") }
    static var broker: Logger { Logger(subsystem: subsystem, category: "secrets-broker") }
    static var control: Logger { Logger(subsystem: subsystem, category: "control") }
}
