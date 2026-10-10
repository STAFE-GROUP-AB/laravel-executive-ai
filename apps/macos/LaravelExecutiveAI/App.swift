// Laravel Executive AI — Sprint 0 app shell (S0-25, blueprint §13.1).
//
// Deliberately empty: one window with a placeholder and one MenuBarExtra with placeholder
// content. No installer (S1-05), no IPC (S1-08), no Supervisor (ADR-001 D3), no networking.
// What is here exists to prove the project layout, the DesignTokens dependency and the CI lane.

import DesignTokens
import SwiftUI

@main
struct LaravelExecutiveAIApp: App {
    var body: some Scene {
        WindowGroup("Laravel Executive AI") {
            ShellPlaceholderView()
        }
        .defaultSize(width: 520, height: 320)

        // Blueprint §1.1: the panel lists the latest brief, system status, approvals and
        // shortcuts. S1-09 fills it against the fixture brief; this is the placeholder only.
        MenuBarExtra("Laravel Executive AI", systemImage: "brain") {
            MenuBarPlaceholderView()
        }
    }
}

struct ShellPlaceholderView: View {
    var body: some View {
        VStack(alignment: .leading, spacing: Tokens.Spacing.s3) {
            Text("Laravel Executive AI — Sprint 0 shell")
                .font(Tokens.Typography.titleLarge)
                .foregroundStyle(Tokens.Colors.textPrimary.dynamic)

            Text("The app builds, tests and renders design tokens. Everything else arrives in Sprint 1.")
                .font(Tokens.Typography.body)
                .foregroundStyle(Tokens.Colors.textSecondary.dynamic)

            Text(ShellInfo.versionLabel)
                .font(Tokens.Typography.mono)
                .foregroundStyle(Tokens.Colors.textMuted.dynamic)
        }
        .padding(Tokens.Spacing.s6)
        .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .topLeading)
        .background(Tokens.Colors.canvas.dynamic)
    }
}

struct MenuBarPlaceholderView: View {
    var body: some View {
        VStack(alignment: .leading, spacing: Tokens.Spacing.s2) {
            Text("No brief yet")
                .font(Tokens.Typography.headline)
                .foregroundStyle(Tokens.Colors.textPrimary.dynamic)

            Text("The first brief is generated once Sprint 1 services are installed.")
                .font(Tokens.Typography.footnote)
                .foregroundStyle(Tokens.Colors.textMuted.dynamic)

            Divider()

            // §1.1: "Quit UI" is not "Stop server". Today the shell runs nothing else, so quitting
            // the UI is the whole story. From S1 on, the Supervisor LaunchAgent keeps the Laravel
            // runtime alive after this process exits; S1-09 proves that with a test.
            Button("Quit UI") {
                NSApplication.shared.terminate(nil)
            }
            .keyboardShortcut("q")
        }
        .padding(Tokens.Spacing.s3)
        .frame(width: Tokens.Sizing.menuBarPanelWidth, alignment: .leading)
    }
}

/// Small, test-visible facts about the running shell.
enum ShellInfo {
    static var bundleIdentifier: String {
        Bundle.main.bundleIdentifier ?? ""
    }

    static var marketingVersion: String {
        Bundle.main.object(forInfoDictionaryKey: "CFBundleShortVersionString") as? String ?? ""
    }

    static var buildNumber: String {
        Bundle.main.object(forInfoDictionaryKey: "CFBundleVersion") as? String ?? ""
    }

    static var versionLabel: String {
        "\(bundleIdentifier) · \(marketingVersion) (\(buildNumber))"
    }
}
