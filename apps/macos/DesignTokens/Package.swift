// swift-tools-version: 6.0
//
// Design tokens for the SwiftUI Mac app (S0-24). Mirrors resources/css/tokens.css.
// Built standalone with `swift build` / `swift test` from this directory; the Xcode
// app project (S0-25) adds it as a local package dependency.

import PackageDescription

let package = Package(
    name: "DesignTokens",
    platforms: [
        .macOS(.v15),
    ],
    products: [
        .library(name: "DesignTokens", targets: ["DesignTokens"]),
    ],
    targets: [
        .target(
            name: "DesignTokens",
            swiftSettings: [
                .swiftLanguageMode(.v6),
            ]
        ),
        .testTarget(
            name: "DesignTokensTests",
            dependencies: ["DesignTokens"]
        ),
    ]
)
