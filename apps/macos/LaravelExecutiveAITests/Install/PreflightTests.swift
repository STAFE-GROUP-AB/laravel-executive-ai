// The ten support-matrix §6 checks: every one runs, blockers carry the EN/SV text verbatim,
// warnings never reach the blocker list, and the never-blocking checks never block.

import Foundation
import XCTest
@testable import LaravelExecutiveAI

final class PreflightTests: XCTestCase {
    private let paths = InstallPaths.rooted(at: URL(fileURLWithPath: "/tmp/lxai-preflight-tests"))

    private func run(_ probes: StubPreflightProbes, language: PreflightLanguage = .en, thisVersion: String = "0.1.0") async -> PreflightReport {
        let context = PreflightContext(paths: paths, thisVersion: thisVersion, language: language)

        return await Preflight(probes: probes, context: context).run()
    }

    func testAllTenChecksRunInMatrixOrderOnASupportedMac() async {
        let report = await run(StubPreflightProbes())

        XCTAssertEqual(report.results.map(\.id), PreflightCheckID.allCases)
        XCTAssertEqual(PreflightCheckID.allCases.count, 10)
        XCTAssertEqual(PreflightCheckID.developerSignature.number, 10)
        XCTAssertTrue(report.canContinue)
        XCTAssertEqual(report.blockers, [])
        XCTAssertEqual(report.selectedPort, 8787)
        XCTAssertEqual(report.existingInstall, .fresh)
        XCTAssertEqual(report.fileVaultEnabled, true)
        XCTAssertEqual(report.warnings.map(\.id), [.fileVault], "FileVault on is logged as a login note, never shown")
        XCTAssertEqual(report.summary?.port, 8787)
    }

    func testEveryCheckStillRunsAfterABlockerAndBlockersKeepMatrixOrder() async {
        var probes = StubPreflightProbes()
        probes.osVersion = OperatingSystemVersion(majorVersion: 14, minorVersion: 7, patchVersion: 1)
        probes.arm64 = false
        probes.signatures = [SignedExecutableResult(name: "app", valid: false, detail: "adhoc")]
        probes.network = .offline

        let report = await run(probes, language: .sv)

        XCTAssertEqual(report.results.count, 10)
        XCTAssertEqual(report.blockers.map(\.check), [.macOSVersion, .appleSilicon, .developerSignature])
        XCTAssertFalse(report.blockers.contains { $0.check == .network }, "network is a warning, never on screen 2")
        XCTAssertEqual(report.blockers[0].message.headline(.sv), "Den här datorn kör macOS 14.7.1. Laravel Executive AI kräver macOS 15 eller senare.")
        XCTAssertEqual(report.blockers[0].message.action(.sv), "Uppdatera macOS i Systeminställningar › Allmänt › Programuppdatering och kör sedan installeraren igen.")
        XCTAssertEqual(report.blockers[0].message.headline(.en), "This Mac is running macOS 14.7.1. Laravel Executive AI needs macOS 15 or later.")
        XCTAssertEqual(report.blockers[2].message.headline(.sv), "Den här kopian av Laravel Executive AI är inte korrekt signerad och kan ha ändrats.")
        XCTAssertEqual(report.warnings.map(\.id), [.network, .fileVault])
    }

    func testNewerMacOSThanTestedIsAWarningNotABlocker() async {
        var probes = StubPreflightProbes()
        probes.osVersion = OperatingSystemVersion(majorVersion: 27, minorVersion: 0, patchVersion: 0)

        let report = await run(probes)
        XCTAssertTrue(report.canContinue)
        XCTAssertEqual(report.result(.macOSVersion)?.outcome.isWarning, true)
    }

    func testRosettaTranslationIsTheIntelBlocker() async {
        var probes = StubPreflightProbes()
        probes.translated = true

        let report = await run(probes)
        XCTAssertEqual(report.blockers.map(\.check), [.appleSilicon])
        XCTAssertEqual(report.blockers[0].message.action(.en), "Intel Macs are not supported and no Intel version is planned.")
    }

    func testMemoryBlockerFormatsTheAmount() async {
        var probes = StubPreflightProbes()
        probes.memory = 4 * 1024 * 1024 * 1024

        let report = await run(probes)
        XCTAssertEqual(report.blockers.map(\.check), [.memory])
        let ram = PreflightMessages.format(bytes: 4 * 1024 * 1024 * 1024)
        XCTAssertEqual(report.blockers[0].message.headline(.en), "This Mac has \(ram) of memory. Laravel Executive AI needs at least 8 GB.")
        XCTAssertTrue(report.blockers[0].message.headlineSV.hasPrefix("Den här datorn har "))
    }

    func testDiskThresholdsBlockWarnAndPass() async {
        var probes = StubPreflightProbes()

        probes.availableDisk = 1_500_000_000
        let blocked = await run(probes)
        XCTAssertEqual(blocked.blockers.map(\.check), [.freeDisk])
        let required = PreflightMessages.format(bytes: 2_000_000_000)
        let available = PreflightMessages.format(bytes: 1_500_000_000)
        XCTAssertEqual(blocked.blockers[0].message.headline(.en), "Not enough free space. The install needs \(required) free on this disk and \(available) is available.")
        XCTAssertEqual(blocked.blockers[0].message.action(.sv), "Frigör utrymme (Apple-menyn › Om den här datorn › Lagring) och kör installeraren igen.")

        probes.availableDisk = 3_000_000_000
        let warned = await run(probes)
        XCTAssertTrue(warned.canContinue)
        XCTAssertEqual(warned.result(.freeDisk)?.outcome.isWarning, true)

        probes.availableDisk = nil
        let unknown = await run(probes)
        XCTAssertTrue(unknown.canContinue, "an unreadable value is logged, not blocked")
    }

    func testGuestRootOrReadOnlyLibraryBlocks() async {
        var probes = StubPreflightProbes()
        probes.user = SessionUser(name: "Guest", isConsoleUser: true, isGuest: true, isRoot: false)
        let guest = await run(probes)
        XCTAssertEqual(guest.blockers.map(\.check), [.permissions])

        probes.user = SessionUser(name: "root", isConsoleUser: true, isGuest: false, isRoot: true)
        let rootUser = await run(probes)
        XCTAssertEqual(rootUser.blockers.map(\.check), [.permissions])

        probes = StubPreflightProbes()
        probes.writable = false
        let report = await run(probes)
        XCTAssertEqual(report.blockers.map(\.check), [.permissions])
        XCTAssertEqual(report.blockers[0].message.headline(.sv), "Laravel Executive AI kan inte skriva till den här användarens Bibliotek-mapp.")
    }

    func testPortFallbackIsAWarningAndAllTakenIsABlocker() async {
        var probes = StubPreflightProbes()
        probes.takenPorts = [8787]

        let fallback = await run(probes)
        XCTAssertTrue(fallback.canContinue)
        XCTAssertEqual(fallback.selectedPort, 8788)
        XCTAssertEqual(fallback.result(.portCollision)?.outcome.isWarning, true)

        probes.takenPorts = Set(8787...8799)
        let blocked = await run(probes)
        XCTAssertEqual(blocked.blockers.map(\.check), [.portCollision])
        XCTAssertNil(blocked.selectedPort)
        XCTAssertNil(blocked.summary)
        XCTAssertEqual(blocked.blockers[0].message.headline(.en), "Another program is using every network port Laravel Executive AI can run on (127.0.0.1:8787–8799).")
    }

    func testNetworkAndFileVaultNeverBlock() async {
        var probes = StubPreflightProbes()
        probes.network = NetworkState(satisfied: true, expensive: true, constrained: true, interfaceTypes: ["cellular"])
        probes.fileVault = .off

        let report = await run(probes)
        XCTAssertTrue(report.canContinue)
        XCTAssertTrue(report.networkIsExpensiveOrConstrained)
        XCTAssertEqual(report.fileVaultEnabled, false)
        XCTAssertEqual(Set(report.warnings.map(\.id)), [.network, .fileVault])

        probes.network = .offline
        probes.fileVault = .unknown
        let offline = await run(probes)
        XCTAssertTrue(offline.canContinue)
        XCTAssertNil(offline.fileVaultEnabled)
    }

    func testExistingInstallRoutesReinstallAndBlocksDowngrade() async {
        var probes = StubPreflightProbes()
        probes.existing = ExistingInstallFacts(installedVersion: "0.0.9", dataDirectoryOwnedByCurrentUser: true, supervisorStatus: "enabled")

        let upgrade = await run(probes)
        XCTAssertTrue(upgrade.canContinue)
        XCTAssertEqual(upgrade.existingInstall, .reinstallOrUpgrade)

        probes.existing = ExistingInstallFacts(installedVersion: "0.2.0", dataDirectoryOwnedByCurrentUser: true, supervisorStatus: "enabled")
        let newer = await run(probes)
        XCTAssertEqual(newer.blockers.map(\.check), [.existingInstall])
        XCTAssertEqual(newer.existingInstall, .blocked)
        XCTAssertEqual(newer.blockers[0].message.headline(.en), "A newer version of Laravel Executive AI (0.2.0) is already installed. This installer is version 0.1.0.")
        XCTAssertEqual(newer.blockers[0].message.headline(.sv), "En nyare version av Laravel Executive AI (0.2.0) är redan installerad. Den här installeraren är version 0.1.0.")

        probes.existing = ExistingInstallFacts(installedVersion: nil, dataDirectoryOwnedByCurrentUser: false, supervisorStatus: "notRegistered")
        let foreign = await run(probes)
        XCTAssertEqual(foreign.blockers.map(\.check), [.existingInstall])
    }

    func testVersionComparison() {
        XCTAssertEqual(PreflightChecks.compareVersions("0.2.0", "0.10.0"), .orderedAscending)
        XCTAssertEqual(PreflightChecks.compareVersions("1.0", "1.0.0"), .orderedSame)
        XCTAssertEqual(PreflightChecks.compareVersions("2.0.0", "1.9.9"), .orderedDescending)
    }

    func testLanguagePreference() {
        XCTAssertEqual(PreflightLanguage.preferred(from: ["sv-SE", "en"]), .sv)
        XCTAssertEqual(PreflightLanguage.preferred(from: ["sv"]), .sv)
        XCTAssertEqual(PreflightLanguage.preferred(from: ["en-SE", "sv"]), .en)
        XCTAssertEqual(PreflightLanguage.preferred(from: []), .en)
    }

    func testShellParityIsDocumentationOnly() {
        for check in PreflightCheckID.allCases {
            XCTAssertFalse(check.shellParity.isEmpty, "\(check) documents its shell parity")
        }

        // No blocker text ever contains a command for the person to paste (§12.1).
        let messages = [
            PreflightMessages.macOSTooOld(version: "14.0"), PreflightMessages.intel,
            PreflightMessages.tooLittleMemory(bytes: 1), PreflightMessages.notEnoughDisk(required: 1, available: 0),
            PreflightMessages.cannotWriteLibrary, PreflightMessages.allPortsTaken(first: 8787, last: 8799),
            PreflightMessages.newerVersionInstalled(installed: "2", this: "1"), PreflightMessages.notCorrectlySigned,
        ]

        for message in messages {
            for text in [message.headlineEN, message.actionEN, message.headlineSV, message.actionSV] {
                XCTAssertFalse(text.contains("$"), text)
                XCTAssertFalse(text.contains("sudo"), text)
                XCTAssertFalse(text.contains("codesign"), text)
                XCTAssertFalse(text.contains("Terminal"), text)
            }
        }
    }

    func testRealProbesAnswerWithoutPrompting() async {
        // The system probes run on this Mac as a smoke test: they must return, never prompt.
        let probes = SystemPreflightProbes(supervisorPlistName: "se.stafe.executive-ai.supervisor.plist", networkWait: .milliseconds(500))

        XCTAssertGreaterThanOrEqual(probes.operatingSystemVersion().majorVersion, 15)
        XCTAssertFalse(probes.operatingSystemBuild().isEmpty)
        XCTAssertTrue(probes.isArm64())
        XCTAssertGreaterThan(probes.physicalMemoryBytes(), 0)
        XCTAssertNotNil(probes.availableCapacityBytes(forVolumeContaining: FileManager.default.homeDirectoryForCurrentUser))
        XCTAssertFalse(probes.sessionUser().isRoot)
        XCTAssertNotEqual(probes.fileVaultState(), .unknown)
        _ = await probes.networkState()

        // An unsigned/ad-hoc test host has no Team ID, so the self-check must fail closed for
        // every bundled executable — the app and, since S1-07, the Supervisor.
        let signatures = probes.signedExecutables()
        XCTAssertEqual(signatures.map(\.name), ["app", "supervisor"])
        XCTAssertTrue(signatures.allSatisfy { !$0.valid })
    }
}
