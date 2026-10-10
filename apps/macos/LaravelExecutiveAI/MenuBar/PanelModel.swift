// The menu bar panel's state (S1-09; blueprint §1.1, §14; health contract §7).
//
// Two paths that never meet:
//
// - `openPanel()` — synchronous, reads the brief cache only. No client call, no generation. The
//   panel renders whatever it has: the cached brief and the last health answer.
// - `refresh()` — the scheduled task (every 30 s while the app runs, and once right after the
//   panel opened, detached from rendering). Health first; the brief only when health's
//   `latest_brief.{id,version}` differs from the cached ETag; approvals only when health says
//   there are any.
//
// The state is derived from the health fields exactly as the contract's §7 table and precedence
// say. The badge is `pending_approvals` and nothing else.

import Foundation
import Observation

enum PanelError: Hashable, Sendable {
    /// `ready == false`: the failing `checks[].name`s, in document order.
    case notReady(failingChecks: [String])
    /// The latest brief's schema major is above what this build renders (brief schema §4).
    case updateRequired(found: String)
    /// `brief_state == error`.
    case brief
    /// `ai_state == error`.
    case ai
}

enum PanelState: Hashable, Sendable {
    /// No health answer yet and nothing cached.
    case loading
    case coreUnreachable
    case error(PanelError)
    /// `brief_state` is `stale` or `none`.
    case stale
    /// `ai_state` is `not_configured` or `configured_untested` — a banner, not an error.
    case aiSetupRequired
    case fresh

    /// The menu bar icon for the state. `MenuBarSymbolTests` proves each resolves on macOS 15.
    var systemImage: String {
        switch self {
        case .loading, .fresh: "brain"
        case .coreUnreachable: "bolt.slash"
        case .error: "exclamationmark.triangle"
        case .stale: "clock.badge.exclamationmark"
        case .aiSetupRequired: "gearshape"
        }
    }

    var title: String {
        switch self {
        case .loading: MenuBarCopy.stateLoading
        case .coreUnreachable: MenuBarCopy.stateCoreUnreachable
        case .error(.notReady): MenuBarCopy.stateNotReady
        case .error(.updateRequired): MenuBarCopy.stateUpdateRequired
        case .error(.brief): MenuBarCopy.stateBriefError
        case .error(.ai): MenuBarCopy.stateAiError
        case .stale: MenuBarCopy.stateStale
        case .aiSetupRequired: MenuBarCopy.stateAiSetupRequired
        case .fresh: MenuBarCopy.stateFresh
        }
    }

    var detail: String {
        switch self {
        case .loading: MenuBarCopy.loadingDetail
        case .coreUnreachable: MenuBarCopy.coreUnreachableDetail
        case .error(.notReady(let checks)): "\(MenuBarCopy.notReadyDetail) \(MenuBarCopy.failingChecksPrefix): \(checks.joined(separator: ", "))."
        case .error(.updateRequired): MenuBarCopy.updateRequiredDetail
        case .error(.brief): MenuBarCopy.briefErrorDetail
        case .error(.ai): MenuBarCopy.aiErrorDetail
        case .stale: MenuBarCopy.staleDetail
        case .aiSetupRequired: MenuBarCopy.aiSetupRequiredDetail
        case .fresh: ""
        }
    }

    var isError: Bool {
        switch self {
        case .coreUnreachable, .error: true
        default: false
        }
    }
}

enum Reachability: Equatable, Sendable {
    case unknown
    case reachable
    case unreachable
}

@MainActor
@Observable
final class PanelModel {
    static let refreshInterval: Duration = .seconds(30)

    private(set) var health: HealthDocument?
    private(set) var reachability: Reachability = .unknown
    private(set) var brief: BriefParityPayload?
    private(set) var briefETag: String?
    private(set) var briefStoredAt: String?
    private(set) var briefRefused: PanelError?
    private(set) var approvals: [PendingApproval] = []
    private(set) var aiActionsPaused = false
    private(set) var pauseFailed = false
    private(set) var isPanelOpen = false
    private(set) var isRefreshing = false
    private(set) var lastRefreshAt: Date?
    private(set) var refreshCount = 0

    let client: any CoreClient
    let cache: BriefCache
    let ports: PanelPorts

    @ObservationIgnored private var refreshLoop: Task<Void, Never>?

    init(client: any CoreClient, cache: BriefCache, ports: PanelPorts) {
        self.client = client
        self.cache = cache
        self.ports = ports
        loadCache()
    }

    deinit {
        refreshLoop?.cancel()
    }

    // MARK: - Derived state (health contract §7)

    /// Precedence: core unreachable > not ready > update required > brief error > stale > AI error >
    /// AI setup required > fresh. Connectivity is informational and lives in `connectivityNote`.
    var state: PanelState {
        if reachability == .unreachable {
            return .coreUnreachable
        }

        guard let health else {
            return brief == nil ? .loading : .stale
        }

        if !health.ready {
            return .error(.notReady(failingChecks: health.failingChecks.map(\.name)))
        }

        if let briefRefused {
            return .error(briefRefused)
        }

        switch health.briefState {
        case .error:
            return .error(.brief)
        case .stale, .none, .unknown:
            return .stale
        case .fresh:
            break
        }

        switch health.aiState {
        case .error:
            return .error(.ai)
        case .notConfigured, .configuredUntested, .unknown:
            return .aiSetupRequired
        case .ready:
            break
        }

        return health.aiReady ? .fresh : .aiSetupRequired
    }

    /// Health contract §7: `pending_approvals > 0` is the only badge driver. Stale, offline, error
    /// and AI states never badge, and an unreachable core clears it.
    var badgeCount: Int {
        guard reachability != .unreachable, let health else {
            return 0
        }

        return max(0, health.pendingApprovals)
    }

    /// "AI setup required" whenever `ai_ready == false`; "AI ready" only when it is true (§0, §3).
    var aiStatusText: String {
        if aiActionsPaused {
            return MenuBarCopy.aiPaused
        }

        guard let health else {
            return MenuBarCopy.stateAiSetupRequired
        }

        switch health.aiState {
        case .error:
            return MenuBarCopy.stateAiError
        case .ready where health.aiReady:
            return MenuBarCopy.aiReady
        case .ready, .notConfigured, .configuredUntested, .unknown:
            return MenuBarCopy.stateAiSetupRequired
        }
    }

    var connectivityNote: String? {
        switch health?.connectivity {
        case .offline: MenuBarCopy.offline
        case .degraded: MenuBarCopy.degraded
        case .online, .unknown, nil: nil
        }
    }

    var privacyMode: String? {
        health?.privacyMode ?? brief?.privacyMode
    }

    var briefStateIsStale: Bool {
        guard let health else {
            return false
        }

        switch health.briefState {
        case .stale, .none, .unknown: return true
        case .fresh, .error: return false
        }
    }

    /// `latest_brief.generated_at` from health when present (it is newer than the cache can be),
    /// else the cached document's own `generated_at`.
    var generatedAtText: String? {
        health?.latestBrief?.generatedAt ?? brief?.generatedAt
    }

    var latestBriefVersion: Int? {
        health?.latestBrief?.version ?? brief?.version
    }

    var latestBriefId: String? {
        health?.latestBrief?.id ?? brief?.id
    }

    /// Brief items that carry an action id: the §1.1 "action shortcuts".
    var actionShortcuts: [BriefParityPayload.Item] {
        brief?.items.filter { !$0.actionIds.isEmpty } ?? []
    }

    // MARK: - Open (cache only, §14)

    /// Called when the panel appears. Touches the cache and nothing else.
    func openPanel() {
        isPanelOpen = true

        if brief == nil {
            loadCache()
        }
    }

    func closePanel() {
        isPanelOpen = false
    }

    private func loadCache() {
        guard let cached = cache.load() else {
            return
        }

        do {
            brief = try BriefParityPayload.fromDocument(cached.document)
            briefETag = cached.etag
            briefStoredAt = cached.storedAt
            briefRefused = nil
        } catch BriefParityError.unsupportedSchemaMajor(let found, _) {
            briefRefused = .updateRequired(found: found)
        } catch {
            try? cache.clear()
        }
    }

    // MARK: - Refresh (scheduled, never on the open path)

    /// Starts the 30-second loop (ipc-contract §6.1 polling, §6.5 heartbeat). Idempotent.
    func startRefreshing(interval: Duration = PanelModel.refreshInterval) {
        guard refreshLoop == nil else {
            return
        }

        refreshLoop = Task { [weak self] in
            while !Task.isCancelled {
                await self?.refresh()

                try? await Task.sleep(for: interval)
            }
        }
    }

    func stopRefreshing() {
        refreshLoop?.cancel()
        refreshLoop = nil
    }

    func refresh() async {
        guard !isRefreshing else {
            return
        }

        isRefreshing = true
        refreshCount += 1

        defer {
            isRefreshing = false
            lastRefreshAt = Date()
        }

        let document: HealthDocument

        do {
            document = try await client.health()
        } catch {
            reachability = .unreachable

            return
        }

        reachability = .reachable
        health = document

        if let actions = document.aiActions, actions != .unknown {
            aiActionsPaused = actions == .paused
        }

        if let latest = document.latestBrief, latest.etag != briefETag || brief == nil {
            await fetchBrief()
        }

        if document.pendingApprovals > 0 {
            approvals = (try? await client.pendingApprovals().items) ?? approvals
        } else {
            approvals = []
        }

        _ = try? await client.heartbeat(panelOpen: isPanelOpen)
    }

    private func fetchBrief() async {
        do {
            switch try await client.latestBrief(ifNoneMatch: briefETag) {
            case .notModified:
                return
            case .document(let data, let etag):
                let parsed = try JSONValue.parse(data)
                let payload = try BriefParityPayload.fromDocument(parsed)
                let storedAt = Date()

                try cache.store(document: parsed, etag: etag, storedAt: storedAt)
                brief = payload
                briefETag = etag
                briefStoredAt = storedAt.formatted(.iso8601)
                briefRefused = nil
            }
        } catch BriefParityError.unsupportedSchemaMajor(let found, _) {
            briefRefused = .updateRequired(found: found)
        } catch {
            // Keep the cached brief; the state table shows the health-derived state.
        }
    }

    // MARK: - Actions (§1.1)

    func openFullBrief() {
        guard let id = latestBriefId else {
            openPWA()

            return
        }

        ports.urlOpener.open(client.origin.appending(path: "briefs").appending(path: id))
    }

    func openPWA() {
        ports.urlOpener.open(client.origin.appending(path: "home"))
    }

    func openDiagnostics() {
        ports.urlOpener.open(client.origin.appending(path: "admin"))
    }

    func openApproval(_ approval: PendingApproval) {
        guard let url = URL(string: approval.openUrl) else {
            return
        }

        ports.urlOpener.open(url)
    }

    func openAction(id: String) {
        ports.urlOpener.open(client.origin.appending(path: "approvals").appending(path: id))
    }

    func togglePauseAI() async {
        pauseFailed = false

        do {
            let result = aiActionsPaused ? try await client.resumeAI() : try await client.pauseAI()
            aiActionsPaused = result.isPaused
        } catch {
            pauseFailed = true
        }
    }

    func openSettings() {
        ports.settings.presentSettings()
    }

    func restartServices() async {
        try? await ports.serviceControl.restartServices()
    }

    /// §1.1 "Quit UI" ≠ "Stop server": ends this process and nothing else. The Supervisor
    /// LaunchAgent and the core keep running; `menubar_integration` ages out 120 s later by design
    /// (ipc-contract §6.5) and the Supervisor does not restart on that.
    func quitUI() {
        stopRefreshing()
        ports.terminator.terminateUI()
    }
}

extension PanelModel {
    /// What the app runs. The core client stays `UnreachableCoreClient` until S1-08 part 2; in a
    /// DEBUG build `LXAI_MENUBAR_FIXTURE=<health fixture name>` swaps in the fixture client (how
    /// the state screenshots are produced). Under XCTest the cache lives in a temporary directory
    /// and no refresh loop starts, so a test run never writes to ~/Library/Caches.
    static func live(bundle: Bundle = .main) -> PanelModel {
        let environment = ProcessInfo.processInfo.environment
        let bundleIdentifier = bundle.bundleIdentifier ?? "se.stafe.executive-ai.app"
        let underTest = environment["XCTestBundlePath"] != nil || environment["XCTestConfigurationFilePath"] != nil
        var client: any CoreClient = UnreachableCoreClient()
        var cache = BriefCache.default(bundleIdentifier: bundleIdentifier)

        if underTest {
            cache = BriefCache(directory: FileManager.default.temporaryDirectory.appending(path: "lxai-menubar-test-\(UUID().uuidString)"))
        }

        #if DEBUG
        if let name = environment["LXAI_MENUBAR_FIXTURE"], let fixture = HealthFixture(rawValue: name),
           let root = FixtureCoreClient.repositoryFixtureRoot() {
            client = FixtureCoreClient(fixtureRoot: root, health: fixture)
            cache = BriefCache(directory: FileManager.default.temporaryDirectory.appending(path: "lxai-menubar-fixture-\(UUID().uuidString)"))
        }
        #endif

        let model = PanelModel(client: client, cache: cache, ports: .live)

        if !underTest {
            model.startRefreshing()
        }

        return model
    }
}
