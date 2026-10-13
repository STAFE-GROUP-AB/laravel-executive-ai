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
        let artisan = Self.artisan

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
                arguments: artisan([
                    "queue:work", "database",
                    "--queue=briefs,email-sync,default,ai", "--sleep=3", "--tries=3",
                ]),
                workingDirectory: runtime.directory,
                environment: environment
            ),
            ServiceSpec(
                name: .scheduler,
                executable: frankenphp,
                arguments: artisan(["schedule:work"]),
                workingDirectory: runtime.directory,
                environment: environment
            ),
        ]
    }

    /// The argument vector that runs an artisan command under FrankenPHP's `php-cli`.
    ///
    /// **`php-cli` does not lay out `$argv` the way a normal PHP CLI does.** Measured on the
    /// shipped binary (FrankenPHP 1.13.0, PHP 8.5.11) with a script that printed its own `$argv`:
    ///
    ///     frankenphp php-cli script.php migrate --force
    ///     0 => …/frankenphp      ← the binary keeps index 0
    ///     1 => script.php        ← the script, where a normal CLI puts the first argument
    ///     2 => migrate
    ///     3 => --force
    ///
    /// `$_SERVER['argv']` is identical, and Symfony's `ArgvInput` drops exactly one element to
    /// skip the script name. So artisan read `app/artisan` as the command name and answered
    /// `Command "app/artisan" is not defined` — which is what the owner's `migrate.log` said on
    /// 2026-10-13, and what the queue worker and the scheduler would have said too, because all
    /// three were spawned the same way. The core is unaffected: `run --config Caddyfile` is a
    /// Caddy command, not a PHP script.
    ///
    /// Rather than compensate for the shift — which would be wrong again the day `php-cli` behaves
    /// like a normal CLI, and FrankenPHP already gates some `php-cli` behaviour on the PHP version
    /// — this **states** the argument vector artisan should see and then loads it. `$argv` and
    /// `$argc` are both set because `ArgvInput` reads `$_SERVER['argv']` while other code reads
    /// `$argv`, and `require` resolves against the working directory, which every spec sets to the
    /// runtime directory.
    ///
    /// The bootstrap is a fixed string with the command words in it verbatim, so the argv guard in
    /// `SupervisorMigrationsTests` still greps the whole vector for `migrate:fresh`, `db:wipe` and
    /// the rest and would still fail on one.
    ///
    /// Known cosmetic effect: artisan's `#!/usr/bin/env php` shebang is echoed, because PHP only
    /// strips one for the primary script and not for a required file. One line per start, in the
    /// child's own log. Not worth a second mechanism to suppress.
    static func artisan(_ command: [String]) -> [String] {
        let literals = (["artisan"] + command)
            .map { "'" + $0.replacingOccurrences(of: "\\", with: "\\\\").replacingOccurrences(of: "'", with: "\\'") + "'" }
            .joined(separator: ", ")

        let bootstrap = "$_SERVER['argv'] = $argv = [\(literals)]; "
            + "$_SERVER['argc'] = $argc = \(command.count + 1); "
            + "require 'app/artisan';"

        return ["php-cli", "-r", bootstrap]
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
            arguments: ServiceSpec.artisan(["migrate", "--force"]),
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
