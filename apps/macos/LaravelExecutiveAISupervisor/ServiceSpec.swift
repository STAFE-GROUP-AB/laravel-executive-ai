// What to spawn, and with which environment (ADR-001 D3 commands, runtime-layout.md §3).
//
// The environment is `RuntimeEnvironment.supervisorEnvironment` plus `LEA_SECRETS_SOCKET` and
// nothing else — not even the Supervisor's own environment is inherited, so a secret can never
// leak into a child by accident (ADR-003 D7 row 12, threat model T-38). `SupervisorSpawnTests`
// proves it against the secrets corpus.
//
// Two kinds of child are launched through the same rules: the three long-lived `ServiceSpec`s,
// and the one-shot `OneShotSpec` that has to finish before them (services.md §2 step 6).

import Foundation

/// What a spawn needs, and all it needs. Both kinds of child are described by this, so neither
/// can acquire an environment or a working directory the other does not have.
protocol ProcessLaunch: Sendable {
    /// Base name of the log file under `LEA_LOG_DIR`, and the label in a spawn error. Never a
    /// path: it is interpolated into log lines (ADR-003 D7 row 6).
    var logName: String { get }
    var executable: URL { get }
    var arguments: [String] { get }
    var workingDirectory: URL { get }
    var environment: [String: String] { get }
}

struct ServiceSpec: ProcessLaunch, Equatable, Sendable {
    let name: ServiceName
    let executable: URL
    let arguments: [String]
    let workingDirectory: URL
    let environment: [String: String]

    var logName: String { name.rawValue }

    /// The three ADR-001 D3 children for an installed runtime.
    static func coreServices(runtime: InstalledRuntime, paths: InstallPaths, port: Int, secretsSocket: URL) -> [ServiceSpec] {
        let environment = childEnvironment(runtime: runtime, paths: paths, port: port, secretsSocket: secretsSocket)
        let frankenphp = frankenphp(in: runtime)

        return [
            ServiceSpec(
                name: .core,
                executable: frankenphp,
                arguments: ["run", "--config", "Caddyfile"],
                workingDirectory: runtime.directory,
                environment: environment
            ),
            ServiceSpec(
                name: .worker,
                executable: frankenphp,
                arguments: [
                    "php-cli", "app/artisan", "queue:work", "database",
                    "--queue=briefs,email-sync,default,ai", "--sleep=3", "--tries=3",
                ],
                workingDirectory: runtime.directory,
                environment: environment
            ),
            ServiceSpec(
                name: .scheduler,
                executable: frankenphp,
                arguments: ["php-cli", "app/artisan", "schedule:work"],
                workingDirectory: runtime.directory,
                environment: environment
            ),
        ]
    }

    /// One dictionary for every child, built once per boot: the three services and the migration
    /// one-shot are spawned with the *same* environment, so there is no second place a key could
    /// be added to (ADR-003 D7 row 12).
    static func childEnvironment(runtime: InstalledRuntime, paths: InstallPaths, port: Int, secretsSocket: URL) -> [String: String] {
        var environment = RuntimeEnvironment.supervisorEnvironment(runtime: runtime, paths: paths, port: port)
        environment[RuntimeEnvironment.secretsSocketKey] = secretsSocket.path

        return environment
    }

    fileprivate static func frankenphp(in runtime: InstalledRuntime) -> URL {
        runtime.directory.appending(path: "frankenphp")
    }
}

/// A child that must run to completion, and exit 0, before the supervised three are spawned. It
/// is never restarted, never probed and never part of `services()` — `ServiceName` is the
/// ipc-contract §7.2 wire enum and stays at `core|worker|scheduler`.
struct OneShotSpec: ProcessLaunch, Equatable, Sendable {
    let logName: String
    let executable: URL
    let arguments: [String]
    let workingDirectory: URL
    let environment: [String: String]

    /// `artisan migrate --force` — the only database command the Supervisor ever runs.
    ///
    /// Nothing else in the product applies migrations, and the health contract makes
    /// `database_migrations` ("zero pending") a hard dependency of `ready`, so without this step
    /// a real install can never become ready (health-contract.md §2).
    ///
    /// `migrate` is **forward-only and idempotent**: it applies the pending migrations in the
    /// bundle and does nothing when there are none, which is what makes it safe to run on every
    /// boot. `migrate:fresh`, `migrate:refresh`, `migrate:rollback` and `db:wipe` are forbidden
    /// here and everywhere else — the owner's data directory survives a reinstall by design
    /// (installer.md §3, threat model T-72) and an upgrade must never destroy it.
    /// `--force` because there is no tty to confirm the production guard on.
    static func migrations(runtime: InstalledRuntime, paths: InstallPaths, port: Int, secretsSocket: URL) -> OneShotSpec {
        OneShotSpec(
            logName: "migrate",
            executable: ServiceSpec.frankenphp(in: runtime),
            arguments: ["php-cli", "app/artisan", "migrate", "--force"],
            workingDirectory: runtime.directory,
            environment: ServiceSpec.childEnvironment(runtime: runtime, paths: paths, port: port, secretsSocket: secretsSocket)
        )
    }
}

extension RuntimeEnvironment {
    /// Absolute path of the secrets broker's Unix socket (ADR-003 D3). Documented in
    /// runtime-layout.md §3; read by `App\Security\Secrets\SupervisorSecretStore`.
    static let secretsSocketKey = "LEA_SECRETS_SOCKET"
}
