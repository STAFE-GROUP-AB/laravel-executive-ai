import SwiftUI
import XCTest
@testable import DesignTokens

final class TokensTests: XCTestCase {

    func testHexRoundTrip() {
        XCTAssertEqual(Tokens.RGB(0x0F5F70).hexString, "#0f5f70")
        XCTAssertEqual(Tokens.RGB(0xFFFFFF).hexString, "#ffffff")
        XCTAssertEqual(Tokens.RGB(0x000000).hexString, "#000000")
    }

    /// Pins the values that resources/css/tokens.css declares, so the two sources cannot drift silently.
    func testMirrorsCssTokens() {
        XCTAssertEqual(Tokens.Colors.canvas.light.hexString, "#f4f4f2")
        XCTAssertEqual(Tokens.Colors.canvas.dark.hexString, "#0e1013")
        XCTAssertEqual(Tokens.Colors.textPrimary.light.hexString, "#15181c")
        XCTAssertEqual(Tokens.Colors.textPrimary.dark.hexString, "#f0f2f4")
        XCTAssertEqual(Tokens.Colors.textMuted.light.hexString, "#60666f")
        XCTAssertEqual(Tokens.Colors.accent.light.hexString, "#0f5f70")
        XCTAssertEqual(Tokens.Colors.accent.dark.hexString, "#63bfd1")
        XCTAssertEqual(Tokens.Colors.danger.light.hexString, "#b4231a")
    }

    func testTextOnSurfacesMeetsWcagAA() {
        let pairs: [(Tokens.ThemedColor, Tokens.ThemedColor)] = [
            (Tokens.Colors.textPrimary, Tokens.Colors.canvas),
            (Tokens.Colors.textSecondary, Tokens.Colors.surface),
            (Tokens.Colors.textMuted, Tokens.Colors.surfaceSunken),
            (Tokens.Colors.textOnAccent, Tokens.Colors.accent),
            (Tokens.Colors.dangerText, Tokens.Colors.dangerSurface),
        ]

        for (foreground, background) in pairs {
            XCTAssertGreaterThanOrEqual(contrast(foreground.light, background.light), 4.5)
            XCTAssertGreaterThanOrEqual(contrast(foreground.dark, background.dark), 4.5)
        }
    }

    func testResolvedColorsDifferBetweenSchemes() {
        let token = Tokens.Colors.textPrimary

        XCTAssertNotEqual(token.resolved(for: .light), token.resolved(for: .dark))
    }

    func testReduceMotionCollapsesDurations() {
        XCTAssertEqual(Tokens.Motion.duration(Tokens.Motion.slow, reduceMotion: true), 0)
        XCTAssertEqual(Tokens.Motion.duration(Tokens.Motion.slow, reduceMotion: false), 0.26)
    }

    private func luminance(_ rgb: Tokens.RGB) -> Double {
        func channel(_ value: UInt8) -> Double {
            let s = Double(value) / 255

            return s <= 0.03928 ? s / 12.92 : pow((s + 0.055) / 1.055, 2.4)
        }

        return 0.2126 * channel(rgb.red) + 0.7152 * channel(rgb.green) + 0.0722 * channel(rgb.blue)
    }

    private func contrast(_ a: Tokens.RGB, _ b: Tokens.RGB) -> Double {
        let l1 = luminance(a)
        let l2 = luminance(b)

        return (max(l1, l2) + 0.05) / (min(l1, l2) + 0.05)
    }
}
