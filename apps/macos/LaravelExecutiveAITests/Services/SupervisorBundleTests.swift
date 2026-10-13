// The build wires the Supervisor into the app bundle exactly as ADR-001 D3 (amended by A24) says:
// a helper application bundle at Contents/Helpers/LaravelExecutiveAISupervisor.app, the agent
// plist at Contents/Library/LaunchAgents/, and the plist's keys. The test host is the built app,
// so this is the real bundle.

import Foundation
import XCTest
@testable import LaravelExecutiveAI

final class SupervisorBundleTests: XCTestCase {
    private var prefix: String {
        SupervisorIdentity.bundlePrefix(fromAppIdentifier: Bundle.main.bundleIdentifier ?? "")
    }

    private var helperBundleURL: URL {
        Bundle.main.bundleURL.appending(path: SystemPreflightProbes.SignedExecutable.supervisorBundlePath)
    }

    func testSupervisorExecutableIsEmbeddedInTheHelperBundle() {
        let executable = helperBundleURL.appending(path: "Contents/MacOS/LaravelExecutiveAISupervisor")

        XCTAssertTrue(FileManager.default.isExecutableFile(atPath: executable.path), executable.path)
        XCTAssertFalse(
            FileManager.default.fileExists(atPath: Bundle.main.bundleURL.appending(path: "Contents/MacOS/LaravelExecutiveAISupervisor").path),
            "the bare tool is gone: a restricted entitlement needs the bundle's embedded profile (A24)"
        )
    }

    /// A24: the helper is an application bundle so it can carry `embedded.provisionprofile`, it is
    /// headless (no Dock icon, no app switcher entry), and it names the app it belongs to so
    /// `SupervisorIdentity` does not mistake the helper's own identifier for the app's.
    func testHelperBundleIsAHeadlessApplicationThatNamesItsApp() throws {
        let helper = try XCTUnwrap(Bundle(url: helperBundleURL))

        XCTAssertEqual(helper.bundleIdentifier, "\(prefix).supervisor")
        XCTAssertEqual(helper.object(forInfoDictionaryKey: "CFBundlePackageType") as? String, "APPL")
        XCTAssertEqual(helper.object(forInfoDictionaryKey: "LSUIElement") as? Bool, true)
        XCTAssertEqual(helper.object(forInfoDictionaryKey: "LSBackgroundOnly") as? Bool, true)
        XCTAssertEqual(
            helper.object(forInfoDictionaryKey: SupervisorIdentity.appBundleIdentifierInfoKey) as? String,
            Bundle.main.bundleIdentifier
        )
        XCTAssertEqual(helper.object(forInfoDictionaryKey: "CFBundleExecutable") as? String, "LaravelExecutiveAISupervisor")
    }

    func testLaunchAgentPlistIsEmbeddedAndWellFormed() throws {
        let plistName = InstallDependencies.supervisorPlistName(bundlePrefix: prefix)
        let url = Bundle.main.bundleURL.appending(path: "Contents/Library/LaunchAgents").appending(path: plistName)
        let data = try Data(contentsOf: url)
        var format = PropertyListSerialization.PropertyListFormat.xml
        let plist = try XCTUnwrap(try PropertyListSerialization.propertyList(from: data, options: [], format: &format) as? [String: Any])

        XCTAssertEqual(format, .xml)
        XCTAssertEqual(plist["Label"] as? String, "\(prefix).supervisor")
        XCTAssertEqual(plist["BundleProgram"] as? String, "Contents/Helpers/LaravelExecutiveAISupervisor.app/Contents/MacOS/LaravelExecutiveAISupervisor")
        XCTAssertEqual(
            plist["AssociatedBundleIdentifiers"] as? [String],
            [Bundle.main.bundleIdentifier ?? "", "\(prefix).supervisor"],
            "Login Items attributes the job to the app and to the helper that is launched"
        )

        // The path in the plist is the executable the build actually produced.
        let program = Bundle.main.bundleURL.appending(path: try XCTUnwrap(plist["BundleProgram"] as? String))
        XCTAssertTrue(FileManager.default.isExecutableFile(atPath: program.path), program.path)
        XCTAssertEqual(plist["RunAtLoad"] as? Bool, true)
        XCTAssertEqual(plist["KeepAlive"] as? Bool, true)
        XCTAssertEqual(plist["ThrottleInterval"] as? Int, 10)
        XCTAssertEqual(plist["ProcessType"] as? String, "Background")
        XCTAssertEqual(plist["ExitTimeOut"] as? Int, 30)
        XCTAssertEqual(plist["LimitLoadToSessionType"] as? String, "Aqua")
        XCTAssertEqual((plist["MachServices"] as? [String: Any])?.keys.sorted(), [SupervisorControlWire.machServiceName(bundlePrefix: prefix)])

        XCTAssertNil(plist["Program"], "BundleProgram, never an absolute Program")
        XCTAssertNil(plist["ProgramArguments"])
        XCTAssertNil(plist["EnvironmentVariables"], "no secret and no per-machine value travels through launchd")
        XCTAssertNil(plist["UserName"])

        let text = String(decoding: data, as: UTF8.self)
        XCTAssertFalse(text.contains("/Users/"))
        XCTAssertFalse(text.contains("base64:"))
    }

    func testPlistNameMatchesTheRegistrarAndTheRepositoryFile() {
        let plistName = InstallDependencies.supervisorPlistName(bundlePrefix: prefix)
        let repositoryCopy = TestFiles.repositoryRoot.appending(path: "packaging/manifest/launchd").appending(path: plistName)

        XCTAssertEqual(plistName, "\(prefix).supervisor.plist")
        XCTAssertTrue(FileManager.default.fileExists(atPath: repositoryCopy.path), "the embedded plist is the repository template")
        XCTAssertEqual(SMAppServiceRegistrar(plistName: plistName).plistName, plistName)
    }

    func testHealthFixturesParseIntoProbeResults() throws {
        let directory = TestFiles.repositoryRoot.appending(path: "packaging/fixtures/health")

        let failing = try LoopbackHealthProbe.parseReady(Data(contentsOf: directory.appending(path: "not-ready-scheduler-failing.json")))
        XCTAssertFalse(failing.ready)
        XCTAssertEqual(failing.checks["scheduler_selftest"], .fail)
        XCTAssertEqual(failing.checks["queue_worker"], .ok)
        XCTAssertEqual(failing.checks.count, 7)

        let ready = try LoopbackHealthProbe.parseReady(Data(contentsOf: directory.appending(path: "ready-core-ready-no-ai.json")))
        XCTAssertTrue(ready.ready)
        XCTAssertTrue(ready.checks.values.allSatisfy { $0 == .ok })

        XCTAssertThrowsError(try LoopbackHealthProbe.parseReady(Data("{\"status\":\"ok\"}".utf8)))
    }
}
