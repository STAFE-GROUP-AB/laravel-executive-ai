// BOOT_SERVICES hand-off (S1-05 defines, S1-07 implements).
//
// ADR-001 D3: one SMAppService LaunchAgent, the Supervisor, registered from this state. If macOS
// answers `.requiresApproval`, the person is sent to System Settings › Login Items — that is a
// click, never an admin password (§12.2).

import Foundation

struct InstalledRuntime: Equatable, Sendable {
    let version: String
    let directory: URL
}

enum BootServicesOutcome: Equatable, Sendable {
    case started
    /// `SMAppService.Status.requiresApproval`: the person must allow the login item.
    case requiresLoginItemApproval
}

enum ServiceBootError: Error, Equatable, Sendable {
    case unavailable(String)
    case failed(String)
}

protocol ServiceBooting: Sendable {
    func boot(runtime: InstalledRuntime, port: Int) async throws -> BootServicesOutcome
    /// Opens System Settings › General › Login Items. Returns without waiting.
    func openLoginItemsSettings()
}

struct UnavailableServiceBooter: ServiceBooting {
    func boot(runtime: InstalledRuntime, port: Int) async throws -> BootServicesOutcome {
        throw ServiceBootError.unavailable("Service supervision arrives with the Supervisor LaunchAgent (S1-07).")
    }

    func openLoginItemsSettings() {}
}

final class ScriptedServiceBooter: ServiceBooting, @unchecked Sendable {
    private let lock = NSLock()
    private var script: [Result<BootServicesOutcome, ServiceBootError>]
    private(set) var boots: [(version: String, port: Int)] = []
    private(set) var openedSettings = 0

    init(_ script: [Result<BootServicesOutcome, ServiceBootError>] = [.success(.started)]) {
        precondition(!script.isEmpty)
        self.script = script
    }

    func boot(runtime: InstalledRuntime, port: Int) async throws -> BootServicesOutcome {
        let next = lock.withLock {
            boots.append((runtime.version, port))

            return script.count > 1 ? script.removeFirst() : script[0]
        }

        return try next.get()
    }

    func openLoginItemsSettings() {
        lock.withLock {
            openedSettings += 1
        }
    }
}
