// The real probes behind the ten preflight checks (support matrix §6), each with the macOS API
// the matrix names. No privileges, no admin prompt, no Terminal. The one `Process` launch
// (`fdesetup status`, §6.9) is the matrix's own choice — there is no public Swift API — and it
// runs as the current user without an Authorization prompt (measurement M8).

import Darwin
import Foundation
import Network
import Security
import ServiceManagement
import SystemConfiguration

struct SystemPreflightProbes: PreflightProbes {
    /// Executables whose signatures the self-check validates (§6.10, ADR-003 D5).
    struct SignedExecutable: Sendable {
        let name: String
        let url: URL
    }

    let executables: [SignedExecutable]
    let signatureChecker: (any CodeSignatureChecking)?
    let supervisorPlistName: String
    let networkWait: Duration

    /// - Parameters:
    ///   - bundle: the GUI app bundle; its own signature is always checked.
    ///   - bundledRuntimeExecutable: the runtime binary inside the bundle, when present.
    ///   - supervisorPlistName: the ADR-001 D3 LaunchAgent plist name (prefix from A12).
    init(
        bundle: Bundle = .main,
        bundledRuntimeExecutable: URL? = nil,
        supervisorPlistName: String,
        networkWait: Duration = .seconds(2)
    ) {
        var executables = [SignedExecutable(name: "app", url: bundle.bundleURL)]

        let supervisor = bundle.bundleURL.appending(path: "Contents/MacOS/LaravelExecutiveAISupervisor")

        if FileManager.default.fileExists(atPath: supervisor.path) {
            executables.append(SignedExecutable(name: "supervisor", url: supervisor))
        }

        if let bundledRuntimeExecutable {
            executables.append(SignedExecutable(name: "runtime", url: bundledRuntimeExecutable))
        }

        self.executables = executables
        signatureChecker = try? SecStaticCodeSignatureChecker.fromRunningApp()
        self.supervisorPlistName = supervisorPlistName
        self.networkWait = networkWait
    }

    // 6.1
    func operatingSystemVersion() -> OperatingSystemVersion {
        ProcessInfo.processInfo.operatingSystemVersion
    }

    func operatingSystemBuild() -> String {
        Self.sysctlString("kern.osversion") ?? "unknown"
    }

    // 6.2
    func isArm64() -> Bool {
        Self.sysctlInt("hw.optional.arm64") == 1
    }

    func isTranslated() -> Bool {
        Self.sysctlInt("sysctl.proc_translated") == 1
    }

    func hardwareModel() -> String {
        Self.sysctlString("hw.model") ?? "unknown"
    }

    func cpuBrand() -> String {
        Self.sysctlString("machdep.cpu.brand_string") ?? "unknown"
    }

    // 6.3
    func physicalMemoryBytes() -> UInt64 {
        ProcessInfo.processInfo.physicalMemory
    }

    // 6.4
    func availableCapacityBytes(forVolumeContaining url: URL) -> Int64? {
        // The user-data directory may not exist yet; walk up to the first existing ancestor.
        var candidate = url

        while !FileManager.default.fileExists(atPath: candidate.path), candidate.pathComponents.count > 1 {
            candidate = candidate.deletingLastPathComponent()
        }

        let values = try? candidate.resourceValues(forKeys: [.volumeAvailableCapacityForImportantUsageKey])

        return values?.volumeAvailableCapacityForImportantUsage
    }

    // 6.5
    func canWriteApplicationSupport(at url: URL) -> Bool {
        let fileManager = FileManager.default
        let probe = url.appending(path: ".preflight-\(UUID().uuidString)")

        do {
            try fileManager.createDirectory(at: probe, withIntermediateDirectories: true)
            try fileManager.removeItem(at: probe)

            return true
        } catch {
            return false
        }
    }

    func sessionUser() -> SessionUser {
        let name = NSUserName()
        var consoleUID: uid_t = 0
        var consoleGID: gid_t = 0
        let consoleUser = SCDynamicStoreCopyConsoleUser(nil, &consoleUID, &consoleGID) as String?

        return SessionUser(
            name: name,
            isConsoleUser: consoleUser == name || consoleUID == getuid(),
            isGuest: name == "Guest",
            isRoot: getuid() == 0
        )
    }

    // 6.6 — bind() without SO_REUSEADDR, then close.
    func canBindLoopback(port: UInt16) -> Bool {
        let descriptor = socket(AF_INET, SOCK_STREAM, 0)

        guard descriptor >= 0 else {
            return false
        }

        defer { close(descriptor) }

        var address = sockaddr_in()
        address.sin_len = UInt8(MemoryLayout<sockaddr_in>.size)
        address.sin_family = sa_family_t(AF_INET)
        address.sin_port = port.bigEndian
        address.sin_addr.s_addr = inet_addr("127.0.0.1")

        let result = withUnsafePointer(to: &address) { pointer in
            pointer.withMemoryRebound(to: sockaddr.self, capacity: 1) { sockaddrPointer in
                bind(descriptor, sockaddrPointer, socklen_t(MemoryLayout<sockaddr_in>.size))
            }
        }

        return result == 0
    }

    // 6.7 — NWPathMonitor, first update or the wait budget, no traffic.
    func networkState() async -> NetworkState {
        let monitor = NWPathMonitor()
        let box = PathBox()

        monitor.pathUpdateHandler = { path in
            box.resume(with: path)
        }

        monitor.start(queue: DispatchQueue(label: "preflight.network"))
        defer { monitor.cancel() }

        let path = await box.value(timeout: networkWait)

        guard let path else {
            return NetworkState(satisfied: false, expensive: false, constrained: false, interfaceTypes: [])
        }

        return NetworkState(
            satisfied: path.status == .satisfied,
            expensive: path.isExpensive,
            constrained: path.isConstrained,
            interfaceTypes: path.availableInterfaces.map { Self.describe($0.type) }
        )
    }

    // 6.8 — filesystem and SMAppService status only. The loopback `/health/live` probe of §6.8
    // arrives with the IPC client (S1-08); until then an existing install is recognised by its
    // runtime link and data directory.
    func existingInstall(paths: InstallPaths) -> ExistingInstallFacts {
        let fileManager = FileManager.default
        var installedVersion: String?

        if let target = try? fileManager.destinationOfSymbolicLink(atPath: paths.currentRuntimeLink.path) {
            let manifest = paths.runtimeRoot.appending(path: target).appending(path: PackageVerifier.manifestFileName)

            if let data = try? Data(contentsOf: manifest), let decoded = try? JSONDecoder().decode(RuntimeManifest.self, from: data) {
                installedVersion = decoded.runtimeVersion
            } else {
                installedVersion = target
            }
        }

        var dataOwnedByCurrentUser: Bool?

        if let attributes = try? fileManager.attributesOfItem(atPath: paths.dataRoot.path) {
            let owner = (attributes[.ownerAccountID] as? NSNumber)?.uint32Value
            dataOwnedByCurrentUser = owner == getuid()
        }

        let status = SMAppService.agent(plistName: supervisorPlistName).status

        return ExistingInstallFacts(
            installedVersion: installedVersion,
            dataDirectoryOwnedByCurrentUser: dataOwnedByCurrentUser,
            supervisorStatus: Self.describe(status)
        )
    }

    // 6.9
    func fileVaultState() -> FileVaultState {
        if let output = Self.runReadOnly("/usr/bin/fdesetup", arguments: ["status"]) {
            if output.contains("FileVault is On") {
                return .on
            }

            if output.contains("FileVault is Off") {
                return .off
            }
        }

        if let output = Self.runReadOnly("/usr/sbin/diskutil", arguments: ["apfs", "list"]) {
            let lines = output.split(separator: "\n").map { $0.trimmingCharacters(in: .whitespaces) }

            if lines.contains(where: { $0.hasPrefix("FileVault:") && $0.contains("Yes") }) {
                return .on
            }

            if lines.contains(where: { $0.hasPrefix("FileVault:") && $0.contains("No") }) {
                return .off
            }
        }

        return .unknown
    }

    // 6.10
    func signedExecutables() -> [SignedExecutableResult] {
        guard let signatureChecker else {
            return executables.map { SignedExecutableResult(name: $0.name, valid: false, detail: "no Team ID in this copy of the app") }
        }

        return executables.map { executable in
            do {
                try signatureChecker.validate(executableAt: executable.url)

                return SignedExecutableResult(name: executable.name, valid: true, detail: "")
            } catch let error as PackageVerificationError {
                return SignedExecutableResult(name: executable.name, valid: false, detail: error.userFacingReason)
            } catch {
                return SignedExecutableResult(name: executable.name, valid: false, detail: "unexpected error")
            }
        }
    }

    // MARK: - Helpers

    static func sysctlInt(_ name: String) -> Int32? {
        var value: Int32 = 0
        var size = MemoryLayout<Int32>.size

        guard sysctlbyname(name, &value, &size, nil, 0) == 0 else {
            return nil
        }

        return value
    }

    static func sysctlString(_ name: String) -> String? {
        var size = 0

        guard sysctlbyname(name, nil, &size, nil, 0) == 0, size > 0 else {
            return nil
        }

        var buffer = [CChar](repeating: 0, count: size)

        guard sysctlbyname(name, &buffer, &size, nil, 0) == 0 else {
            return nil
        }

        return buffer.withUnsafeBufferPointer { pointer in
            String(cString: pointer.baseAddress!)
        }
    }

    /// Runs a read-only system tool as the current user and returns stdout, or nil on any failure.
    static func runReadOnly(_ executable: String, arguments: [String]) -> String? {
        guard FileManager.default.isExecutableFile(atPath: executable) else {
            return nil
        }

        let process = Process()
        process.executableURL = URL(fileURLWithPath: executable)
        process.arguments = arguments
        process.standardInput = FileHandle.nullDevice
        process.standardError = FileHandle.nullDevice

        let pipe = Pipe()
        process.standardOutput = pipe

        do {
            try process.run()
        } catch {
            return nil
        }

        let data = pipe.fileHandleForReading.readDataToEndOfFile()
        process.waitUntilExit()

        guard process.terminationStatus == 0 else {
            return nil
        }

        return String(data: data, encoding: .utf8)
    }

    static func describe(_ type: NWInterface.InterfaceType) -> String {
        switch type {
        case .wifi: return "wifi"
        case .cellular: return "cellular"
        case .wiredEthernet: return "ethernet"
        case .loopback: return "loopback"
        case .other: return "other"
        @unknown default: return "unknown"
        }
    }

    static func describe(_ status: SMAppService.Status) -> String {
        switch status {
        case .enabled: return "enabled"
        case .requiresApproval: return "requiresApproval"
        case .notRegistered: return "notRegistered"
        case .notFound: return "notFound"
        @unknown default: return "unknown"
        }
    }
}

/// One-shot mailbox for the first `NWPath`, with a timeout.
private final class PathBox: @unchecked Sendable {
    private let lock = NSLock()
    private var path: NWPath?
    private var continuation: CheckedContinuation<NWPath?, Never>?

    func resume(with path: NWPath) {
        lock.lock()
        defer { lock.unlock() }

        guard self.path == nil else {
            return
        }

        self.path = path
        continuation?.resume(returning: path)
        continuation = nil
    }

    func value(timeout: Duration) async -> NWPath? {
        if let path = lock.withLock({ path }) {
            return path
        }

        return await withTaskGroup(of: NWPath?.self) { group in
            group.addTask {
                await withCheckedContinuation { continuation in
                    self.lock.lock()

                    if let path = self.path {
                        self.lock.unlock()
                        continuation.resume(returning: path)
                        return
                    }

                    self.continuation = continuation
                    self.lock.unlock()
                }
            }

            group.addTask {
                try? await Task.sleep(for: timeout)
                return nil
            }

            let first = await group.next() ?? nil
            group.cancelAll()
            self.expire()

            return first
        }
    }

    private func expire() {
        lock.lock()
        defer { lock.unlock() }

        continuation?.resume(returning: nil)
        continuation = nil
    }
}
