// Design tokens for SwiftUI (S0-24, blueprint §1.1).
//
// Values mirror resources/css/tokens.css. Change the CSS first, then this file;
// DesignTokensTests pins the hex values so drift is caught in CI.
//
// Usage:
//
//     Text("Latest brief")
//         .font(Tokens.Typography.body)
//         .foregroundStyle(Tokens.Colors.textPrimary.dynamic)
//
//     // or, when a specific scheme is already known:
//     @Environment(\.colorScheme) private var scheme
//     Tokens.Colors.textPrimary.resolved(for: scheme)

import SwiftUI

#if canImport(AppKit)
import AppKit
#endif

public enum Tokens {

    // MARK: - Colors

    /// A color with one sRGB value per appearance.
    public struct ThemedColor: Sendable, Hashable {
        public let light: RGB
        public let dark: RGB

        public init(light: RGB, dark: RGB) {
            self.light = light
            self.dark = dark
        }

        /// Resolves the color for an explicit scheme (useful in previews and tests).
        public func resolved(for scheme: ColorScheme) -> Color {
            let rgb = scheme == .dark ? dark : light

            return rgb.color
        }

        /// A `Color` that follows the current appearance automatically.
        public var dynamic: Color {
            #if canImport(AppKit)
            let light = self.light
            let dark = self.dark
            let nsColor = NSColor(name: nil) { appearance in
                let isDark = appearance.bestMatch(from: [.darkAqua, .aqua]) == .darkAqua

                return (isDark ? dark : light).nsColor
            }

            return Color(nsColor: nsColor)
            #else
            return light.color
            #endif
        }
    }

    /// An opaque sRGB color stored as 0...255 channels, constructed from a 24-bit hex.
    public struct RGB: Sendable, Hashable {
        public let red: UInt8
        public let green: UInt8
        public let blue: UInt8

        public init(_ hex: UInt32) {
            red = UInt8((hex >> 16) & 0xFF)
            green = UInt8((hex >> 8) & 0xFF)
            blue = UInt8(hex & 0xFF)
        }

        public var hexString: String {
            String(format: "#%02x%02x%02x", red, green, blue)
        }

        public var color: Color {
            Color(.sRGB, red: Double(red) / 255, green: Double(green) / 255, blue: Double(blue) / 255, opacity: 1)
        }

        #if canImport(AppKit)
        var nsColor: NSColor {
            NSColor(srgbRed: CGFloat(red) / 255, green: CGFloat(green) / 255, blue: CGFloat(blue) / 255, alpha: 1)
        }
        #endif
    }

    public enum Colors {
        // Surfaces
        public static let canvas = ThemedColor(light: RGB(0xF4F4F2), dark: RGB(0x0E1013))
        public static let surface = ThemedColor(light: RGB(0xFFFFFF), dark: RGB(0x15181C))
        public static let surfaceRaised = ThemedColor(light: RGB(0xFFFFFF), dark: RGB(0x1C2026))
        public static let surfaceSunken = ThemedColor(light: RGB(0xEBEBE8), dark: RGB(0x0A0C0E))
        public static let surfaceInverse = ThemedColor(light: RGB(0x1C1F23), dark: RGB(0xF3F4F5))

        // Text
        public static let textPrimary = ThemedColor(light: RGB(0x15181C), dark: RGB(0xF0F2F4))
        public static let textSecondary = ThemedColor(light: RGB(0x4A4F57), dark: RGB(0xB6BCC4))
        public static let textMuted = ThemedColor(light: RGB(0x60666F), dark: RGB(0x8D949D))
        public static let textInverse = ThemedColor(light: RGB(0xF3F4F5), dark: RGB(0x15181C))
        public static let textOnAccent = ThemedColor(light: RGB(0xFFFFFF), dark: RGB(0x0E1013))
        public static let textDisabled = ThemedColor(light: RGB(0x9A9FA6), dark: RGB(0x5F666F))

        // Borders
        public static let border = ThemedColor(light: RGB(0xE1E2DF), dark: RGB(0x2A2F36))
        public static let borderStrong = ThemedColor(light: RGB(0x878C93), dark: RGB(0x666D77))

        // Accent
        public static let accent = ThemedColor(light: RGB(0x0F5F70), dark: RGB(0x63BFD1))
        public static let accentHover = ThemedColor(light: RGB(0x0B4D5B), dark: RGB(0x7FCDDC))
        public static let accentSubtle = ThemedColor(light: RGB(0xE3F1F3), dark: RGB(0x12303A))
        public static let accentText = ThemedColor(light: RGB(0x0B4D5B), dark: RGB(0x8FD6E3))

        // Semantic
        public static let success = ThemedColor(light: RGB(0x1B7A43), dark: RGB(0x4FC98A))
        public static let successSurface = ThemedColor(light: RGB(0xE4F3EA), dark: RGB(0x11301F))
        public static let successText = ThemedColor(light: RGB(0x135C33), dark: RGB(0x7DDBA7))

        public static let warning = ThemedColor(light: RGB(0x8A5A00), dark: RGB(0xE6B04A))
        public static let warningSurface = ThemedColor(light: RGB(0xFBF1DC), dark: RGB(0x332608))
        public static let warningText = ThemedColor(light: RGB(0x704800), dark: RGB(0xF0C76B))

        public static let danger = ThemedColor(light: RGB(0xB4231A), dark: RGB(0xF2857A))
        public static let dangerSurface = ThemedColor(light: RGB(0xFBE7E5), dark: RGB(0x3A1512))
        public static let dangerText = ThemedColor(light: RGB(0x8F1C14), dark: RGB(0xF6A59C))

        public static let info = ThemedColor(light: RGB(0x2A5BB8), dark: RGB(0x85B4F2))
        public static let infoSurface = ThemedColor(light: RGB(0xE6EEFA), dark: RGB(0x142540))
        public static let infoText = ThemedColor(light: RGB(0x1F4790), dark: RGB(0xA6C8F6))

        // Focus
        public static let focusRing = ThemedColor(light: RGB(0x0F5F70), dark: RGB(0x63BFD1))
    }

    // MARK: - Typography
    //
    // macOS uses the native point scale (body 13pt), not the web rem scale; the names
    // match the CSS tokens so a web `text-lg` and a Mac `Typography.lg` play the same role.
    // Dynamic Type is honoured by building on system text styles where one exists.

    public enum Typography {
        public enum Size {
            public static let xs: CGFloat = 11
            public static let sm: CGFloat = 12
            public static let md: CGFloat = 13
            public static let lg: CGFloat = 15
            public static let xl: CGFloat = 17
            public static let xxl: CGFloat = 22
            public static let xxxl: CGFloat = 28
        }

        public static let caption = Font.system(size: Size.xs, weight: .regular)
        public static let footnote = Font.system(size: Size.sm, weight: .regular)
        public static let body = Font.system(size: Size.md, weight: .regular)
        public static let bodyEmphasis = Font.system(size: Size.md, weight: .medium)
        public static let headline = Font.system(size: Size.lg, weight: .semibold)
        public static let title = Font.system(size: Size.xl, weight: .semibold)
        public static let titleLarge = Font.system(size: Size.xxl, weight: .semibold)
        public static let display = Font.system(size: Size.xxxl, weight: .semibold)
        public static let mono = Font.system(size: Size.sm, weight: .regular, design: .monospaced)

        /// Uppercase section labels: xs, medium, wide tracking (mirrors --tracking-wide).
        public static let label = Font.system(size: Size.xs, weight: .medium)
        public static let labelTracking: CGFloat = 0.04 * Size.xs
    }

    // MARK: - Spacing (4pt base)

    public enum Spacing {
        public static let s0: CGFloat = 0
        public static let s1: CGFloat = 4
        public static let s2: CGFloat = 8
        public static let s3: CGFloat = 12
        public static let s4: CGFloat = 16
        public static let s5: CGFloat = 20
        public static let s6: CGFloat = 24
        public static let s8: CGFloat = 32
        public static let s10: CGFloat = 40
        public static let s12: CGFloat = 48
        public static let s16: CGFloat = 64
        public static let s20: CGFloat = 80
        public static let s24: CGFloat = 96
    }

    // MARK: - Sizing

    public enum Sizing {
        public static let controlSmall: CGFloat = 28
        public static let controlMedium: CGFloat = 36
        public static let controlLarge: CGFloat = 44
        /// Menu bar panel width; keeps the §1.1 panel dense but breathable.
        public static let menuBarPanelWidth: CGFloat = 360
    }

    // MARK: - Radius

    public enum Radius {
        public static let none: CGFloat = 0
        public static let xs: CGFloat = 4
        public static let sm: CGFloat = 6
        public static let md: CGFloat = 8
        public static let lg: CGFloat = 12
        public static let xl: CGFloat = 16
        public static let xxl: CGFloat = 20
        public static let full: CGFloat = 9999
    }

    // MARK: - Elevation

    public struct Shadow: Sendable, Hashable {
        public let opacity: Double
        public let radius: CGFloat
        public let y: CGFloat

        public init(opacity: Double, radius: CGFloat, y: CGFloat) {
            self.opacity = opacity
            self.radius = radius
            self.y = y
        }
    }

    public enum Elevation {
        public static let level1 = Shadow(opacity: 0.06, radius: 1, y: 1)
        public static let level2 = Shadow(opacity: 0.08, radius: 3, y: 2)
        public static let level3 = Shadow(opacity: 0.12, radius: 12, y: 8)
        public static let level4 = Shadow(opacity: 0.18, radius: 24, y: 20)
    }

    // MARK: - Motion

    public enum Motion {
        public static let fast: Double = 0.12
        public static let base: Double = 0.18
        public static let slow: Double = 0.26
        public static let deliberate: Double = 0.40

        /// Honour the system's Reduce Motion setting (mirrors prefers-reduced-motion).
        public static func duration(_ seconds: Double, reduceMotion: Bool) -> Double {
            reduceMotion ? 0 : seconds
        }

        public static func standard(_ seconds: Double = base) -> Animation {
            .timingCurve(0.2, 0, 0, 1, duration: seconds)
        }

        public static func decelerate(_ seconds: Double = base) -> Animation {
            .timingCurve(0, 0, 0, 1, duration: seconds)
        }

        public static func accelerate(_ seconds: Double = fast) -> Animation {
            .timingCurve(0.3, 0, 1, 1, duration: seconds)
        }
    }
}

// MARK: - View helpers

public extension View {
    /// Applies an elevation token as a shadow in the token shadow colour.
    func elevation(_ shadow: Tokens.Shadow) -> some View {
        self.shadow(color: Color(.sRGB, red: 20 / 255, green: 24 / 255, blue: 28 / 255, opacity: shadow.opacity),
                    radius: shadow.radius,
                    x: 0,
                    y: shadow.y)
    }

    /// Minimum hit target for touch-class surfaces (44pt). Mac pointer surfaces may use control sizes.
    func minimumTouchTarget() -> some View {
        frame(minWidth: Tokens.Sizing.controlLarge, minHeight: Tokens.Sizing.controlLarge)
    }
}
