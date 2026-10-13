// Service start-up hand-off (S1-05 defines, S1-07 implements), in the two halves it really has.
//
// ADR-001 D3: one SMAppService LaunchAgent, the Supervisor. The two halves have *different*
// prerequisites, and that is why they run in different states (#124):
//
//   startSupervisor()  needs the activated runtime (INSTALL_CORE) and the recorded port
//                      (PREFLIGHT) — nothing else. It is called at the end of INSTALL_CORE,
//                      because everything after it needs a core, and only the Supervisor can
//                      make one: it is the sole Keychain writer (ADR-003 D2), it mints APP_KEY
//                      and `K` on first boot, it serves the secrets broker the core reads
//                      APP_KEY from (`SecretsServiceProvider`, ADR-001 D4 — there is no .env),
//                      it migrates the database and it spawns the three children
//                      (services.md §2). A core without it does not boot half-way; it does not
//                      boot at all.
//   boot()             needs the choice SELECT_AI collected, so it stays in BOOT_SERVICES: it
//                      confirms supervision is still live and hands the API key to the one
//                      Keychain writer.
//
// `SMAppService.register()` is both halves of "start it now" and "bootstrap it at every login",
// so there is no separate step that makes a transient core persistent — there is no transient
// core in this architecture.
//
// If macOS answers `.requiresApproval`, the person is sent to System Settings › Login Items —
// that is a click, never an admin password (§12.2).

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
    /// Registers the one Supervisor LaunchAgent and waits until it answers on its control
    /// channel. Called at the end of INSTALL_CORE; idempotent, so a retry is cheap.
    func startSupervisor(runtime: InstalledRuntime, port: Int) async throws -> BootServicesOutcome

    /// BOOT_SERVICES: confirms supervision is live and hands over the SELECT_AI key.
    func boot(runtime: InstalledRuntime, port: Int) async throws -> BootServicesOutcome

    /// Opens System Settings › General › Login Items. Returns without waiting.
    func openLoginItemsSettings()
}

struct UnavailableServiceBooter: ServiceBooting {
    func startSupervisor(runtime: InstalledRuntime, port: Int) async throws -> BootServicesOutcome {
        throw ServiceBootError.unavailable("Service supervision arrives with the Supervisor LaunchAgent (S1-07).")
    }

    func boot(runtime: InstalledRuntime, port: Int) async throws -> BootServicesOutcome {
        throw ServiceBootError.unavailable("Service supervision arrives with the Supervisor LaunchAgent (S1-07).")
    }

    func openLoginItemsSettings() {}
}

final class ScriptedServiceBooter: ServiceBooting, @unchecked Sendable {
    private let lock = NSLock()
    private var script: [Result<BootServicesOutcome, ServiceBootError>]
    private var supervisorScript: [Result<BootServicesOutcome, ServiceBootError>]
    private(set) var boots: [(version: String, port: Int)] = []
    private(set) var supervisorStarts: [(version: String, port: Int)] = []
    private(set) var openedSettings = 0

    init(
        _ script: [Result<BootServicesOutcome, ServiceBootError>] = [.success(.started)],
        supervisor: [Result<BootServicesOutcome, ServiceBootError>] = [.success(.started)]
    ) {
        precondition(!script.isEmpty)
        precondition(!supervisor.isEmpty)
        self.script = script
        supervisorScript = supervisor
    }

    func startSupervisor(runtime: InstalledRuntime, port: Int) async throws -> BootServicesOutcome {
        let next = lock.withLock {
            supervisorStarts.append((runtime.version, port))

            return supervisorScript.count > 1 ? supervisorScript.removeFirst() : supervisorScript[0]
        }

        return try next.get()
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
