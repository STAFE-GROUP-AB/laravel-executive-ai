// Shared doubles for the MenuBar tests (S1-09). Every double records what was called so a test
// can prove a path touched nothing it must not touch.

import Foundation
import XCTest
@testable import LaravelExecutiveAI

enum MenuBarFixtures {
    static var root: URL {
        guard let root = FixtureCoreClient.repositoryFixtureRoot() else {
            fatalError("packaging/fixtures not found above \(#filePath)")
        }

        return root
    }

    static var repositoryRoot: URL {
        root.deletingLastPathComponent().deletingLastPathComponent()
    }

    static var menuBarSources: URL {
        repositoryRoot.appending(path: "apps/macos/LaravelExecutiveAI/MenuBar")
    }

    static func health(_ fixture: HealthFixture) throws -> HealthDocument {
        try HealthDocument.decode(try Data(contentsOf: root.appending(path: "health/\(fixture.rawValue).json")))
    }

    static func brief(_ name: String) throws -> Data {
        try Data(contentsOf: root.appending(path: "briefs/\(name).json"))
    }

    static var briefFixtureNames: [String] {
        let files = (try? FileManager.default.contentsOfDirectory(atPath: root.appending(path: "briefs").path)) ?? []

        return files.filter { $0.hasSuffix(".json") }.map { String($0.dropLast(5)) }.sorted()
    }

    static func temporaryDirectory(_ name: String = #function) -> URL {
        let url = FileManager.default.temporaryDirectory.appending(path: "lxai-menubar-tests-\(name)-\(UUID().uuidString)")
        try? FileManager.default.createDirectory(at: url, withIntermediateDirectories: true)

        return url
    }
}

/// Wraps any client and counts every operation. The "open the panel" test asserts `calls == []`.
final class RecordingCoreClient: CoreClient, @unchecked Sendable {
    private let lock = NSLock()
    private let inner: any CoreClient
    private(set) var calls: [String] = []

    var origin: URL {
        inner.origin
    }

    init(_ inner: any CoreClient) {
        self.inner = inner
    }

    var callCount: Int {
        lock.withLock { calls.count }
    }

    private func record(_ name: String) {
        lock.withLock { calls.append(name) }
    }

    func health() async throws -> HealthDocument {
        record("health")

        return try await inner.health()
    }

    func latestBrief(ifNoneMatch etag: String?) async throws -> LatestBriefResponse {
        record("briefs/latest\(etag.map { " if-none-match \($0)" } ?? "")")

        return try await inner.latestBrief(ifNoneMatch: etag)
    }

    func pendingApprovals() async throws -> PendingApprovals {
        record("approvals?state=pending")

        return try await inner.pendingApprovals()
    }

    func pauseAI() async throws -> AiActionsState {
        record("ai/pause")

        return try await inner.pauseAI()
    }

    func resumeAI() async throws -> AiActionsState {
        record("ai/resume")

        return try await inner.resumeAI()
    }

    func heartbeat(panelOpen: Bool) async throws -> HeartbeatAck {
        record("heartbeat")

        return try await inner.heartbeat(panelOpen: panelOpen)
    }
}

final class SpyURLOpener: URLOpening, @unchecked Sendable {
    private let lock = NSLock()
    private(set) var opened: [URL] = []

    func open(_ url: URL) {
        lock.withLock { opened.append(url) }
    }
}

final class SpyTerminator: AppTerminating, @unchecked Sendable {
    private let lock = NSLock()
    private(set) var terminations = 0

    func terminateUI() {
        lock.withLock { terminations += 1 }
    }
}

final class SpySettingsPresenter: SettingsPresenting, @unchecked Sendable {
    private let lock = NSLock()
    private(set) var presentations = 0

    func presentSettings() {
        lock.withLock { presentations += 1 }
    }
}

final class SpyServiceControl: ServiceControlling, @unchecked Sendable {
    private let lock = NSLock()
    private let uninstallError: ServiceControlError?
    private(set) var restarts = 0
    private(set) var stops = 0
    private(set) var uninstalls = 0
    /// The `keepingData` flag of every uninstall call, in order.
    private(set) var uninstallChoices: [Bool] = []

    init(uninstallError: ServiceControlError? = nil) {
        self.uninstallError = uninstallError
    }

    func restartServices() async throws {
        lock.withLock { restarts += 1 }
    }

    func stopServices() async throws {
        lock.withLock { stops += 1 }
    }

    func uninstall(keepingData: Bool) async throws -> UninstallReport {
        lock.withLock {
            uninstalls += 1
            uninstallChoices.append(keepingData)
        }

        if let uninstallError {
            throw uninstallError
        }

        return UninstallReport(
            dataChoice: keepingData ? .keep : .delete,
            performed: UninstallStep.allCases,
            resumedFrom: [],
            secretsSweep: .removed(count: 3),
            preservedData: keepingData ? URL(fileURLWithPath: "/placeholder/data") : nil,
            appBundleTrashed: true
        )
    }
}

struct SpyPorts {
    let urlOpener = SpyURLOpener()
    let terminator = SpyTerminator()
    let settings = SpySettingsPresenter()
    var serviceControl = SpyServiceControl()

    var ports: PanelPorts {
        PanelPorts(urlOpener: urlOpener, terminator: terminator, settings: settings, serviceControl: serviceControl)
    }
}

@MainActor
func makePanelModel(
    health: HealthFixture = .aiReady,
    brief: String = "brief-canonical",
    cacheDirectory: URL = MenuBarFixtures.temporaryDirectory(),
    spies: SpyPorts = SpyPorts()
) -> (model: PanelModel, client: RecordingCoreClient, spies: SpyPorts) {
    let client = RecordingCoreClient(FixtureCoreClient(fixtureRoot: MenuBarFixtures.root, health: health, brief: brief))
    let model = PanelModel(client: client, cache: BriefCache(directory: cacheDirectory), ports: spies.ports)

    return (model, client, spies)
}
