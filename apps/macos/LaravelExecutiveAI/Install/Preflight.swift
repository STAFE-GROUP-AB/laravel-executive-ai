// PREFLIGHT (S1-05) — the ten checks of docs/architecture/support-matrix.md §6, in order.
//
// Each check is a `PreflightCheck` value that reads facts through `PreflightProbes` (so tests
// inject them) and classifies the result as passed, warning or blocker. All ten run even after
// the first blocker so the log is complete; screen 2 shows blockers only, in this order, and the
// first one carries the primary action. Nothing here asks for an admin password or opens
// Terminal; the shell-parity commands are documentation for contributors (`shellParity`).

import Foundation

enum PreflightCheckID: String, CaseIterable, Codable, Sendable, Hashable {
    case macOSVersion = "macos_version"
    case appleSilicon = "apple_silicon"
    case memory = "memory"
    case freeDisk = "free_disk"
    case permissions = "permissions"
    case portCollision = "port_collision"
    case network = "network"
    case existingInstall = "existing_install"
    case fileVault = "filevault"
    case developerSignature = "developer_signature"

    /// 1 … 10, the support-matrix numbering.
    var number: Int {
        Self.allCases.firstIndex(of: self)! + 1
    }

    var title: String {
        switch self {
        case .macOSVersion: return "macOS version"
        case .appleSilicon: return "Apple Silicon"
        case .memory: return "Memory"
        case .freeDisk: return "Free disk"
        case .permissions: return "Permissions"
        case .portCollision: return "Network port"
        case .network: return "Network"
        case .existingInstall: return "Existing install"
        case .fileVault: return "FileVault"
        case .developerSignature: return "Developer signature"
        }
    }

    /// The command a contributor runs to reproduce the check. Documentation, never shown in the UI.
    var shellParity: String {
        switch self {
        case .macOSVersion: return "sw_vers -productVersion"
        case .appleSilicon: return "sysctl hw.optional.arm64 sysctl.proc_translated"
        case .memory: return "sysctl -n hw.memsize"
        case .freeDisk: return "df -h ~/Library"
        case .permissions: return "touch \"$HOME/Library/Application Support/.lxai-preflight\" && rm \"$_\"; stat -f %Su /dev/console"
        case .portCollision: return "nc -z 127.0.0.1 8787"
        case .network: return "scutil -r 1.1.1.1"
        case .existingInstall: return "readlink \"$HOME/Library/Application Support/<bundle id>/runtime/current\""
        case .fileVault: return "fdesetup status"
        case .developerSignature: return "codesign --verify --deep --strict --verbose=2 \"/Applications/Laravel Executive AI.app\""
        }
    }
}

enum PreflightLanguage: String, Codable, Sendable, Hashable {
    case en
    case sv

    /// Swedish when the user's first preferred language is `sv` (support matrix §6).
    static func preferred(from languages: [String] = Locale.preferredLanguages) -> PreflightLanguage {
        guard let first = languages.first?.lowercased() else {
            return .en
        }

        return first == "sv" || first.hasPrefix("sv-") || first.hasPrefix("sv_") ? .sv : .en
    }
}

/// A blocker's user-facing text: a bold headline and one action sentence, EN and SV.
struct PreflightMessage: Equatable, Sendable, Hashable {
    let headlineEN: String
    let actionEN: String
    let headlineSV: String
    let actionSV: String

    func headline(_ language: PreflightLanguage) -> String {
        language == .sv ? headlineSV : headlineEN
    }

    func action(_ language: PreflightLanguage) -> String {
        language == .sv ? actionSV : actionEN
    }
}

enum PreflightOutcome: Equatable, Sendable, Hashable {
    case passed
    case warning(String)
    case blocker(PreflightMessage)

    var isBlocker: Bool {
        if case .blocker = self {
            return true
        }

        return false
    }

    var isWarning: Bool {
        if case .warning = self {
            return true
        }

        return false
    }
}

struct PreflightCheckResult: Equatable, Sendable, Hashable {
    let id: PreflightCheckID
    let outcome: PreflightOutcome
    /// Facts for the install log and diagnostics bundle. Never user names or paths under $HOME.
    let recorded: [String: String]

    init(id: PreflightCheckID, outcome: PreflightOutcome, recorded: [String: String] = [:]) {
        self.id = id
        self.outcome = outcome
        self.recorded = recorded
    }
}

struct PreflightBlocker: Equatable, Sendable, Hashable, Identifiable {
    let check: PreflightCheckID
    let message: PreflightMessage

    var id: PreflightCheckID { check }
}

enum ExistingInstallRouting: String, Codable, Sendable, Hashable {
    case fresh
    case reinstallOrUpgrade = "reinstall_or_upgrade"
    case blocked
}

struct PreflightReport: Equatable, Sendable {
    let results: [PreflightCheckResult]
    let language: PreflightLanguage

    /// Blockers in support-matrix order; the first carries the primary action.
    var blockers: [PreflightBlocker] {
        results.compactMap { result in
            if case .blocker(let message) = result.outcome {
                return PreflightBlocker(check: result.id, message: message)
            }

            return nil
        }
    }

    var warnings: [PreflightCheckResult] {
        results.filter(\.outcome.isWarning)
    }

    var canContinue: Bool {
        blockers.isEmpty
    }

    func result(_ id: PreflightCheckID) -> PreflightCheckResult? {
        results.first { $0.id == id }
    }

    /// The loopback port the port check found free; nil when every candidate was taken.
    var selectedPort: Int? {
        result(.portCollision)?.recorded["selected_port"].flatMap(Int.init)
    }

    var existingInstall: ExistingInstallRouting {
        result(.existingInstall)?.recorded["routing"].flatMap(ExistingInstallRouting.init) ?? .fresh
    }

    var networkIsExpensiveOrConstrained: Bool {
        let recorded = result(.network)?.recorded ?? [:]

        return recorded["expensive"] == "true" || recorded["constrained"] == "true"
    }

    var fileVaultEnabled: Bool? {
        switch result(.fileVault)?.recorded["filevault"] {
        case "on": return true
        case "off": return false
        default: return nil
        }
    }

    var summary: InstallCheckpoint.PreflightSummary? {
        guard let port = selectedPort else {
            return nil
        }

        return InstallCheckpoint.PreflightSummary(
            port: port,
            language: language,
            existingInstall: existingInstall,
            warnings: warnings.map(\.id),
            fileVaultEnabled: fileVaultEnabled
        )
    }
}

// MARK: - Facts the checks read

struct SessionUser: Equatable, Sendable {
    let name: String
    let isConsoleUser: Bool
    let isGuest: Bool
    let isRoot: Bool
}

struct NetworkState: Equatable, Sendable {
    let satisfied: Bool
    let expensive: Bool
    let constrained: Bool
    let interfaceTypes: [String]

    static let online = NetworkState(satisfied: true, expensive: false, constrained: false, interfaceTypes: ["wifi"])
    static let offline = NetworkState(satisfied: false, expensive: false, constrained: false, interfaceTypes: [])
}

struct ExistingInstallFacts: Equatable, Sendable {
    /// Version from `runtime/current/manifest.json`, nil when nothing is installed.
    let installedVersion: String?
    /// nil when there is no data directory.
    let dataDirectoryOwnedByCurrentUser: Bool?
    /// `SMAppService` status name: `enabled`, `requiresApproval`, `notRegistered`, `notFound`.
    let supervisorStatus: String

    static let none = ExistingInstallFacts(installedVersion: nil, dataDirectoryOwnedByCurrentUser: nil, supervisorStatus: "notRegistered")
}

enum FileVaultState: String, Equatable, Sendable {
    case on
    case off
    case unknown
}

struct SignedExecutableResult: Equatable, Sendable {
    let name: String
    let valid: Bool
    let detail: String
}

protocol PreflightProbes: Sendable {
    func operatingSystemVersion() -> OperatingSystemVersion
    func operatingSystemBuild() -> String
    func isArm64() -> Bool
    func isTranslated() -> Bool
    func hardwareModel() -> String
    func cpuBrand() -> String
    func physicalMemoryBytes() -> UInt64
    func availableCapacityBytes(forVolumeContaining url: URL) -> Int64?
    func canWriteApplicationSupport(at url: URL) -> Bool
    func sessionUser() -> SessionUser
    func canBindLoopback(port: UInt16) -> Bool
    func networkState() async -> NetworkState
    func existingInstall(paths: InstallPaths) -> ExistingInstallFacts
    func fileVaultState() -> FileVaultState
    func signedExecutables() -> [SignedExecutableResult]
}

struct PreflightContext: Sendable {
    let paths: InstallPaths
    /// This installer's product version, for the existing-install comparison.
    let thisVersion: String
    let language: PreflightLanguage
    var minimumMacOS = OperatingSystemVersion(majorVersion: 15, minorVersion: 0, patchVersion: 0)
    var highestTestedMajor = 26
    var minimumMemoryBytes: UInt64 = 8 * 1024 * 1024 * 1024
    var blockerDiskBytes: Int64 = 2_000_000_000
    var warningDiskBytes: Int64 = 5_000_000_000
    var preferredPort: UInt16 = 8787
    var fallbackPorts: ClosedRange<UInt16> = 8788...8799

    init(paths: InstallPaths, thisVersion: String, language: PreflightLanguage) {
        self.paths = paths
        self.thisVersion = thisVersion
        self.language = language
    }
}

// MARK: - The checks

struct PreflightCheck: Sendable {
    let id: PreflightCheckID
    let run: @Sendable (any PreflightProbes, PreflightContext) async -> PreflightCheckResult
}

enum PreflightChecks {
    static let all: [PreflightCheck] = [macOSVersion, appleSilicon, memory, freeDisk, permissions, portCollision, network, existingInstall, fileVault, developerSignature]

    // 6.1
    static let macOSVersion = PreflightCheck(id: .macOSVersion) { probes, context in
        let version = probes.operatingSystemVersion()
        let text = "\(version.majorVersion).\(version.minorVersion).\(version.patchVersion)"
        let recorded = ["version": text, "build": probes.operatingSystemBuild()]

        guard Self.isAtLeast(version, context.minimumMacOS) else {
            return PreflightCheckResult(id: .macOSVersion, outcome: .blocker(PreflightMessages.macOSTooOld(version: text)), recorded: recorded)
        }

        if version.majorVersion > context.highestTestedMajor {
            return PreflightCheckResult(id: .macOSVersion, outcome: .warning("macOS \(text) is newer than the highest tested major \(context.highestTestedMajor)"), recorded: recorded)
        }

        return PreflightCheckResult(id: .macOSVersion, outcome: .passed, recorded: recorded)
    }

    // 6.2
    static let appleSilicon = PreflightCheck(id: .appleSilicon) { probes, _ in
        let arm64 = probes.isArm64()
        let translated = probes.isTranslated()
        let recorded = ["arm64": String(arm64), "translated": String(translated), "model": probes.hardwareModel(), "cpu": probes.cpuBrand()]

        guard arm64, !translated else {
            return PreflightCheckResult(id: .appleSilicon, outcome: .blocker(PreflightMessages.intel), recorded: recorded)
        }

        return PreflightCheckResult(id: .appleSilicon, outcome: .passed, recorded: recorded)
    }

    // 6.3
    static let memory = PreflightCheck(id: .memory) { probes, context in
        let bytes = probes.physicalMemoryBytes()
        let recorded = ["physical_memory_bytes": String(bytes)]

        guard bytes >= context.minimumMemoryBytes else {
            return PreflightCheckResult(id: .memory, outcome: .blocker(PreflightMessages.tooLittleMemory(bytes: bytes)), recorded: recorded)
        }

        return PreflightCheckResult(id: .memory, outcome: .passed, recorded: recorded)
    }

    // 6.4
    static let freeDisk = PreflightCheck(id: .freeDisk) { probes, context in
        let available = probes.availableCapacityBytes(forVolumeContaining: context.paths.applicationSupport)
        let recorded = ["available_bytes": available.map(String.init) ?? "unknown", "required_bytes": String(context.blockerDiskBytes)]

        guard let available else {
            return PreflightCheckResult(id: .freeDisk, outcome: .warning("free space could not be determined"), recorded: recorded)
        }

        guard available >= context.blockerDiskBytes else {
            return PreflightCheckResult(id: .freeDisk, outcome: .blocker(PreflightMessages.notEnoughDisk(required: context.blockerDiskBytes, available: available)), recorded: recorded)
        }

        if available < context.warningDiskBytes {
            return PreflightCheckResult(id: .freeDisk, outcome: .warning("less than the recommended free space"), recorded: recorded)
        }

        return PreflightCheckResult(id: .freeDisk, outcome: .passed, recorded: recorded)
    }

    // 6.5
    static let permissions = PreflightCheck(id: .permissions) { probes, context in
        let writable = probes.canWriteApplicationSupport(at: context.paths.applicationSupport)
        let user = probes.sessionUser()
        let recorded = ["writable": String(writable), "console_user": String(user.isConsoleUser), "guest": String(user.isGuest), "root": String(user.isRoot)]

        guard writable, !user.isGuest, !user.isRoot else {
            return PreflightCheckResult(id: .permissions, outcome: .blocker(PreflightMessages.cannotWriteLibrary), recorded: recorded)
        }

        if !user.isConsoleUser {
            return PreflightCheckResult(id: .permissions, outcome: .warning("not the console user"), recorded: recorded)
        }

        return PreflightCheckResult(id: .permissions, outcome: .passed, recorded: recorded)
    }

    // 6.6
    static let portCollision = PreflightCheck(id: .portCollision) { probes, context in
        let candidates = [context.preferredPort] + Array(context.fallbackPorts)
        let first = candidates.first { probes.canBindLoopback(port: $0) }
        var recorded = ["preferred_port": String(context.preferredPort)]

        guard let first else {
            recorded["selected_port"] = nil
            return PreflightCheckResult(id: .portCollision, outcome: .blocker(PreflightMessages.allPortsTaken(first: context.preferredPort, last: context.fallbackPorts.upperBound)), recorded: recorded)
        }

        recorded["selected_port"] = String(first)

        if first != context.preferredPort {
            return PreflightCheckResult(id: .portCollision, outcome: .warning("preferred port \(context.preferredPort) is taken; using \(first)"), recorded: recorded)
        }

        return PreflightCheckResult(id: .portCollision, outcome: .passed, recorded: recorded)
    }

    // 6.7 — never blocks.
    static let network = PreflightCheck(id: .network) { probes, _ in
        let state = await probes.networkState()
        let recorded = ["satisfied": String(state.satisfied), "expensive": String(state.expensive), "constrained": String(state.constrained), "interfaces": state.interfaceTypes.joined(separator: ",")]

        guard state.satisfied else {
            return PreflightCheckResult(id: .network, outcome: .warning("offline; the core installs from the bundle"), recorded: recorded)
        }

        if state.expensive || state.constrained {
            return PreflightCheckResult(id: .network, outcome: .warning("metered or constrained link; no model download will start silently"), recorded: recorded)
        }

        return PreflightCheckResult(id: .network, outcome: .passed, recorded: recorded)
    }

    // 6.8
    static let existingInstall = PreflightCheck(id: .existingInstall) { probes, context in
        let facts = probes.existingInstall(paths: context.paths)
        var recorded = ["supervisor": facts.supervisorStatus, "installed_version": facts.installedVersion ?? "none"]

        if facts.dataDirectoryOwnedByCurrentUser == false {
            recorded["routing"] = ExistingInstallRouting.blocked.rawValue
            return PreflightCheckResult(id: .existingInstall, outcome: .blocker(PreflightMessages.cannotWriteLibrary), recorded: recorded)
        }

        guard let installed = facts.installedVersion else {
            recorded["routing"] = ExistingInstallRouting.fresh.rawValue
            return PreflightCheckResult(id: .existingInstall, outcome: .passed, recorded: recorded)
        }

        if Self.compareVersions(installed, context.thisVersion) == .orderedDescending {
            recorded["routing"] = ExistingInstallRouting.blocked.rawValue
            return PreflightCheckResult(id: .existingInstall, outcome: .blocker(PreflightMessages.newerVersionInstalled(installed: installed, this: context.thisVersion)), recorded: recorded)
        }

        recorded["routing"] = ExistingInstallRouting.reinstallOrUpgrade.rawValue
        return PreflightCheckResult(id: .existingInstall, outcome: .warning("existing install \(installed) found; data-preserving reinstall"), recorded: recorded)
    }

    // 6.9 — never blocks.
    static let fileVault = PreflightCheck(id: .fileVault) { probes, _ in
        let state = probes.fileVaultState()
        let recorded = ["filevault": state.rawValue]

        switch state {
        case .off:
            return PreflightCheckResult(id: .fileVault, outcome: .warning("FileVault is off; the Done checklist recommends enabling it"), recorded: recorded)
        case .on:
            return PreflightCheckResult(id: .fileVault, outcome: .warning("FileVault is on; services start only after login"), recorded: recorded)
        case .unknown:
            return PreflightCheckResult(id: .fileVault, outcome: .warning("FileVault state could not be read"), recorded: recorded)
        }
    }

    // 6.10
    static let developerSignature = PreflightCheck(id: .developerSignature) { probes, _ in
        let results = probes.signedExecutables()
        var recorded: [String: String] = [:]

        for result in results {
            recorded[result.name] = result.valid ? "valid" : "invalid: \(result.detail)"
        }

        guard !results.isEmpty, results.allSatisfy(\.valid) else {
            return PreflightCheckResult(id: .developerSignature, outcome: .blocker(PreflightMessages.notCorrectlySigned), recorded: recorded)
        }

        return PreflightCheckResult(id: .developerSignature, outcome: .passed, recorded: recorded)
    }

    // MARK: Helpers

    static func isAtLeast(_ version: OperatingSystemVersion, _ minimum: OperatingSystemVersion) -> Bool {
        if version.majorVersion != minimum.majorVersion {
            return version.majorVersion > minimum.majorVersion
        }

        if version.minorVersion != minimum.minorVersion {
            return version.minorVersion > minimum.minorVersion
        }

        return version.patchVersion >= minimum.patchVersion
    }

    /// Numeric dotted-version comparison; non-numeric components compare as 0.
    static func compareVersions(_ lhs: String, _ rhs: String) -> ComparisonResult {
        let left = lhs.split(separator: ".").map { Int($0) ?? 0 }
        let right = rhs.split(separator: ".").map { Int($0) ?? 0 }
        let count = max(left.count, right.count)

        for index in 0..<count {
            let a = index < left.count ? left[index] : 0
            let b = index < right.count ? right[index] : 0

            if a != b {
                return a < b ? .orderedAscending : .orderedDescending
            }
        }

        return .orderedSame
    }
}

// MARK: - The runner

struct Preflight: Sendable {
    let probes: any PreflightProbes
    let context: PreflightContext
    let checks: [PreflightCheck]

    init(probes: any PreflightProbes, context: PreflightContext, checks: [PreflightCheck] = PreflightChecks.all) {
        self.probes = probes
        self.context = context
        self.checks = checks
    }

    /// Runs every check in order, blockers or not.
    func run() async -> PreflightReport {
        var results: [PreflightCheckResult] = []

        for check in checks {
            results.append(await check.run(probes, context))
        }

        return PreflightReport(results: results, language: context.language)
    }
}
