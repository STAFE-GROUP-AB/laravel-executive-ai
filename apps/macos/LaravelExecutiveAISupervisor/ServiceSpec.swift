// What to spawn, and with which environment (ADR-001 D3 commands, runtime-layout.md §3).
//
// The environment is `RuntimeEnvironment.supervisorEnvironment` plus `LEA_SECRETS_SOCKET` and
// nothing else — not even the Supervisor's own environment is inherited, so a secret can never
// leak into a child by accident (ADR-003 D7 row 12, threat model T-38). `SupervisorSpawnTests`
// proves it against the secrets corpus.

import Foundation

struct ServiceSpec: Equatable, Sendable {
    let name: ServiceName
    let executable: URL
    let arguments: [String]
    let workingDirectory: URL
    let environment: [String: String]

    /// The three ADR-001 D3 children for an installed runtime.
    static func coreServices(runtime: InstalledRuntime, paths: InstallPaths, port: Int, secretsSocket: URL) -> [ServiceSpec] {
        var environment = RuntimeEnvironment.supervisorEnvironment(runtime: runtime, paths: paths, port: port)
        environment[RuntimeEnvironment.secretsSocketKey] = secretsSocket.path

        let frankenphp = runtime.directory.appending(path: "frankenphp")

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
}

extension RuntimeEnvironment {
    /// Absolute path of the secrets broker's Unix socket (ADR-003 D3). Documented in
    /// runtime-layout.md §3; read by `App\Security\Secrets\SupervisorSecretStore`.
    static let secretsSocketKey = "LEA_SECRETS_SOCKET"
}
