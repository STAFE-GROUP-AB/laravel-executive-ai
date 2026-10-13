// ADR-003 D7 row 12 / threat model T-38: no secret in any child's environment or argv.
// Asserted twice — on the dictionary the Supervisor builds, and on what a real child sees.

import Foundation
import XCTest
@testable import LaravelExecutiveAI

final class SupervisorSpawnTests: XCTestCase {
    private func specs() -> [ServiceSpec] {
        let paths = InstallPaths.rooted(at: URL(fileURLWithPath: "/tmp/lxai-spawn-test"))
        let runtime = InstalledRuntime(version: "0.1.0", directory: paths.runtimeDirectory(version: "0.1.0"))

        return ServiceSpec.coreServices(runtime: runtime, paths: paths, port: 8787, secretsSocket: paths.applicationSupport.appending(path: "run/secrets.sock"))
    }

    func testCoreServicesAreTheThreeAdr001Commands() {
        let specs = specs()

        XCTAssertEqual(specs.map(\.name), [.core, .worker, .scheduler])
        XCTAssertEqual(specs[0].arguments, ["run", "--config", "Caddyfile"])
        // `php-cli -r <bootstrap>`, not `php-cli app/artisan …`: FrankenPHP's `php-cli` leaves
        // its own binary at `$argv[0]`, so Symfony read the script path as the command name.
        // See `ServiceSpec.artisan`, which is where that is explained and where the shape lives.
        XCTAssertEqual(specs[1].arguments, ServiceSpec.artisan(["queue:work", "database", "--queue=briefs,email-sync,default,ai", "--sleep=3", "--tries=3"]))
        XCTAssertEqual(specs[2].arguments, ServiceSpec.artisan(["schedule:work"]))
        XCTAssertTrue(specs[1].arguments.last?.contains("'queue:work'") ?? false, "the command artisan will run")
        XCTAssertTrue(specs[2].arguments.last?.contains("'schedule:work'") ?? false, "the command artisan will run")

        for spec in specs {
            XCTAssertEqual(spec.executable.lastPathComponent, "frankenphp")
            XCTAssertEqual(spec.workingDirectory, spec.environment["LEA_RUNTIME_DIR"].map { URL(fileURLWithPath: $0) })
            XCTAssertFalse(spec.arguments.contains { $0.contains("artisan serve") || $0 == "serve" }, "php artisan serve is forbidden (ADR-001 D1)")
        }
    }

    func testEnvironmentIsExactlyTheContractPlusTheSocket() throws {
        let spec = specs()[0]
        let expectedKeys: Set<String> = [
            "LEA_RUNTIME_DIR", "LEA_DATA_DIR", "LEA_CACHE_DIR", "LEA_LOG_DIR", "LEA_HTTP_PORT",
            "PHPRC", "APP_ENV", "APP_DEBUG", "APP_URL", "LEA_BACKEND_PROFILE", "LEA_SECRETS_SOCKET",
        ]

        XCTAssertEqual(Set(spec.environment.keys), expectedKeys)
        XCTAssertEqual(spec.environment["LEA_SECRETS_SOCKET"], "/tmp/lxai-spawn-test/Application Support/run/secrets.sock")

        for key in spec.environment.keys {
            for forbidden in ["KEY", "TOKEN", "SECRET", "PASSWORD", "PASSWD", "CREDENTIAL"] where key.uppercased().contains(forbidden) {
                XCTAssertEqual(key, "LEA_SECRETS_SOCKET", "the only secret-ish word allowed is the socket path key, which carries a path")
            }
        }

        let corpus = try ServicesTestSupport.secretsCorpusValues()

        for value in spec.environment.values {
            for secret in corpus {
                XCTAssertFalse(value.contains(secret), "environment carries a corpus value")
            }

            XCTAssertFalse(value.hasPrefix("base64:"), "no APP_KEY in the environment")
        }
    }

    func testARealChildSeesOnlyThatEnvironmentAndNoCorpusValue() async throws {
        let spec = specs()[0]
        let sink = CapturingLogSink()
        let child = ChildProcess(
            spec: ServiceSpec(name: .core, executable: ServicesTestSupport.shell, arguments: ["-c", "/usr/bin/env"], workingDirectory: URL(fileURLWithPath: "/"), environment: spec.environment),
            log: sink
        )
        let exited = expectation(description: "exit")

        try await child.start { _ in exited.fulfill() }
        await fulfillment(of: [exited], timeout: 5)
        try await Task.sleep(for: .milliseconds(50))

        let lines = sink.text.split(separator: "\n").map(String.init)
        // `/bin/sh` itself adds PWD, SHLVL and `_`; everything else must be ours.
        let shellOwned: Set<String> = ["PWD", "SHLVL", "_"]
        let keys = Set(lines.compactMap { $0.split(separator: "=", maxSplits: 1).first.map(String.init) }).subtracting(shellOwned)

        XCTAssertEqual(keys, Set(spec.environment.keys), "the child inherits nothing from the test process")
        XCTAssertFalse(keys.contains("HOME"))
        XCTAssertFalse(keys.contains("PATH"))

        for secret in try ServicesTestSupport.secretsCorpusValues() {
            XCTAssertFalse(sink.text.contains(secret))
        }
    }
}
