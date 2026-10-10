// The build wires the Supervisor into the app bundle exactly as ADR-001 D3 says: the executable
// at Contents/MacOS/, the agent plist at Contents/Library/LaunchAgents/, and the plist's keys.
// The test host is the built app, so this is the real bundle.

import Foundation
import XCTest
@testable import LaravelExecutiveAI

final class SupervisorBundleTests: XCTestCase {
    private var prefix: String {
        SupervisorIdentity.bundlePrefix(fromAppIdentifier: Bundle.main.bundleIdentifier ?? "")
    }

    func testSupervisorExecutableIsEmbedded() {
        let executable = Bundle.main.bundleURL.appending(path: "Contents/MacOS/LaravelExecutiveAISupervisor")

        XCTAssertTrue(FileManager.default.isExecutableFile(atPath: executable.path), executable.path)
    }

    func testLaunchAgentPlistIsEmbeddedAndWellFormed() throws {
        let plistName = InstallDependencies.supervisorPlistName(bundlePrefix: prefix)
        let url = Bundle.main.bundleURL.appending(path: "Contents/Library/LaunchAgents").appending(path: plistName)
        let data = try Data(contentsOf: url)
        var format = PropertyListSerialization.PropertyListFormat.xml
        let plist = try XCTUnwrap(try PropertyListSerialization.propertyList(from: data, options: [], format: &format) as? [String: Any])

        XCTAssertEqual(format, .xml)
        XCTAssertEqual(plist["Label"] as? String, "\(prefix).supervisor")
        XCTAssertEqual(plist["BundleProgram"] as? String, "Contents/MacOS/LaravelExecutiveAISupervisor")
        XCTAssertEqual(plist["AssociatedBundleIdentifiers"] as? [String], [Bundle.main.bundleIdentifier ?? ""])
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
