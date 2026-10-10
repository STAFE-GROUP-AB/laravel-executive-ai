// Settings → Uninstall (S1-13; §1.1, §12.2): the confirmation offers Keep my data / Delete
// everything / Cancel, the chosen flag reaches `ServiceControlling.uninstall(keepingData:)`
// unchanged, nothing runs on Cancel, and every phase renders in both languages. With
// LXAI_MENUBAR_SCREENSHOT_DIR set the phases are written as PNGs for the docs.

import AppKit
import SwiftUI
import XCTest
@testable import LaravelExecutiveAI

@MainActor
final class UninstallViewTests: XCTestCase {

    override func tearDown() {
        MenuBarCopy.use(nil)
        super.tearDown()
    }

    func testTheChosenDataOptionReachesServiceControlAndNothingRunsBeforeConfirm() async throws {
        let spies = SpyPorts()
        let model = UninstallModel(serviceControl: spies.serviceControl, terminator: spies.terminator)

        XCTAssertEqual(model.choice, .keep, "keeping data is the default; deleting is the explicit choice")
        XCTAssertEqual(model.phase, .choosing)
        XCTAssertEqual(spies.serviceControl.uninstalls, 0)

        model.choice = .delete
        await model.confirm()

        XCTAssertEqual(spies.serviceControl.uninstallChoices, [false])

        guard case .done(let report) = model.phase else {
            return XCTFail("expected done, got \(model.phase)")
        }

        XCTAssertEqual(report.dataChoice, .delete)
        XCTAssertEqual(spies.terminator.terminations, 0, "the app is not quit for the person; Quit is their last click")

        model.quit()
        XCTAssertEqual(spies.terminator.terminations, 1)
    }

    func testKeepingDataIsHandedOverAsKeepingData() async throws {
        let spies = SpyPorts()
        let model = UninstallModel(serviceControl: spies.serviceControl, terminator: spies.terminator)

        await model.confirm()

        XCTAssertEqual(spies.serviceControl.uninstallChoices, [true])
    }

    func testAFailureShowsTheRetrySafeReasonAndRetryCallsAgain() async throws {
        let spies = SpyPorts(serviceControl: SpyServiceControl(uninstallError: .uninstallFailed("The Supervisor did not answer.")))
        let model = UninstallModel(serviceControl: spies.serviceControl, terminator: spies.terminator)

        await model.confirm()
        XCTAssertEqual(model.phase, .failed("The Supervisor did not answer."))

        await model.confirm()
        XCTAssertEqual(spies.serviceControl.uninstalls, 2, "Try again calls the same retry-safe port")

        model.backToChoice()
        XCTAssertEqual(model.phase, .choosing)
    }

    func testConfirmationRendersInEveryPhaseAndLanguage() async throws {
        let screenshotDirectory = ProcessInfo.processInfo.environment["LXAI_MENUBAR_SCREENSHOT_DIR"].map { URL(fileURLWithPath: $0) }

        if let screenshotDirectory {
            try FileManager.default.createDirectory(at: screenshotDirectory, withIntermediateDirectories: true)
        }

        for language in MenuBarLanguage.allCases {
            MenuBarCopy.use(language)

            let spies = SpyPorts()
            let choosing = UninstallModel(serviceControl: spies.serviceControl, terminator: spies.terminator)
            try render(choosing, name: "uninstall-01-choose-\(language.rawValue)", into: screenshotDirectory)

            choosing.choice = .delete
            try render(choosing, name: "uninstall-02-delete-selected-\(language.rawValue)", into: screenshotDirectory)

            let kept = UninstallModel(serviceControl: spies.serviceControl, terminator: spies.terminator)
            await kept.confirm()
            try render(kept, name: "uninstall-03-done-kept-\(language.rawValue)", into: screenshotDirectory)

            let failed = UninstallModel(serviceControl: SpyServiceControl(uninstallError: .uninstallFailed(UninstallError.supervisorUnreachable("timeout").userFacingReason)), terminator: spies.terminator)
            await failed.confirm()
            try render(failed, name: "uninstall-04-failed-\(language.rawValue)", into: screenshotDirectory)

            let (panel, _, _) = makePanelModel(health: .aiReady)
            let settings = ImageRenderer(content: SettingsView(model: panel))
            settings.scale = 2
            let settingsImage = try XCTUnwrap(settings.nsImage)
            XCTAssertGreaterThan(settingsImage.size.height, 100)
            try write(settingsImage, name: "settings-\(language.rawValue)", into: screenshotDirectory)
        }
    }

    func testUninstallCopyExistsInBothLanguagesAndCarriesNoSecretMarker() {
        for strings in [MenuBarStrings.en, MenuBarStrings.sv] {
            for value in [strings.uninstall, strings.uninstallKeepData, strings.uninstallDeleteEverything, strings.cancel, strings.uninstallTitle, strings.uninstallSecretsSkipped] {
                XCTAssertFalse(value.isEmpty)

                for marker in MenuBarCopyTests.forbidden {
                    XCTAssertFalse(value.localizedCaseInsensitiveContains(marker))
                }
            }
        }

        XCTAssertEqual(MenuBarStrings.sv.uninstall, "Avinstallera…")
        XCTAssertEqual(MenuBarStrings.sv.uninstallKeepData, "Behåll mina data")
        XCTAssertEqual(MenuBarStrings.sv.uninstallDeleteEverything, "Ta bort allt")
        XCTAssertEqual(MenuBarStrings.en.uninstall, "Uninstall…")
    }

    private func render(_ model: UninstallModel, name: String, into directory: URL?) throws {
        let renderer = ImageRenderer(content: UninstallConfirmationView(model: model, onCancel: {}).fixedSize(horizontal: false, vertical: true))
        renderer.scale = 2

        let image = try XCTUnwrap(renderer.nsImage, name)
        XCTAssertGreaterThan(image.size.height, 100, name)
        try write(image, name: name, into: directory)
    }

    private func write(_ image: NSImage, name: String, into directory: URL?) throws {
        guard let directory else {
            return
        }

        let tiff = try XCTUnwrap(image.tiffRepresentation)
        let png = try XCTUnwrap(NSBitmapImageRep(data: tiff)?.representation(using: .png, properties: [:]))
        try png.write(to: directory.appending(path: "\(name).png"))
    }
}
