// Sprint 0 shell tests (S0-25). They prove the CI test lane runs, the xcconfig identity flows into
// the built bundle, and the DesignTokens package is linked. Nothing here exercises behaviour.

import DesignTokens
import XCTest
@testable import LaravelExecutiveAI

final class ShellTests: XCTestCase {

    func testBundleIdentifierUsesTheA12Prefix() {
        XCTAssertEqual(ShellInfo.bundleIdentifier, "se.stafe.executive-ai.app")
        XCTAssertTrue(ShellInfo.bundleIdentifier.hasPrefix("se.stafe.executive-ai."))
    }

    func testVersionFieldsComeFromTheSharedXcconfig() {
        XCTAssertEqual(ShellInfo.marketingVersion, "0.0.0")
        XCTAssertEqual(ShellInfo.buildNumber, "1")
        XCTAssertEqual(ShellInfo.versionLabel, "se.stafe.executive-ai.app · 0.0.0 (1)")
    }

    func testDesignTokensPackageIsLinkedAndResolves() {
        XCTAssertEqual(Tokens.Colors.accent.light.hexString, "#0f5f70")
        XCTAssertEqual(Tokens.Colors.accent.dark.hexString, "#63bfd1")
        XCTAssertEqual(Tokens.Sizing.menuBarPanelWidth, 360)
        XCTAssertNotEqual(Tokens.Colors.textPrimary.resolved(for: .light),
                          Tokens.Colors.textPrimary.resolved(for: .dark))
    }

    @MainActor
    func testHostAppIsTheUnsandboxedGuiBundle() {
        XCTAssertEqual(Bundle.main.bundlePath.hasSuffix("LaravelExecutiveAI.app"), true)
        XCTAssertNil(ProcessInfo.processInfo.environment["APP_SANDBOX_CONTAINER_ID"])
        XCTAssertEqual(Bundle.main.object(forInfoDictionaryKey: "LSUIElement") as? Bool, false)
    }
}
