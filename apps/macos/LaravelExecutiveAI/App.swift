// Laravel Executive AI — app shell (S0-25) with the first-run installer (S1-05, §12.3).
//
// The window shows the installer until the install state is READY, then the placeholder that
// S1-09 replaces. The MenuBarExtra is unchanged. No IPC (S1-08), no Supervisor (S1-07), no
// networking: the live dependencies stop with an actionable error where those items plug in.

import DesignTokens
import SwiftUI

@main
struct LaravelExecutiveAIApp: App {
    @State private var installer = InstallStateMachine(dependencies: LaravelExecutiveAIApp.makeDependencies())

    var body: some Scene {
        WindowGroup("Laravel Executive AI") {
            if installer.showsInstaller {
                InstallerRootView(machine: installer)
            } else {
                ShellPlaceholderView()
            }
        }
        .defaultSize(width: 640, height: 600)

        // Blueprint §1.1: the panel lists the latest brief, system status, approvals and
        // shortcuts. S1-09 fills it against the fixture brief; this is the placeholder only.
        MenuBarExtra("Laravel Executive AI", systemImage: "brain") {
            MenuBarPlaceholderView()
        }
    }

    /// Live dependencies, or — in DEBUG builds started with `LXAI_INSTALL_SIMULATE=1` — a
    /// simulated flow under a temporary directory so the six screens can be walked locally.
    /// As an XCTest host the app keeps everything in memory so a test run never writes to the
    /// real user-data or log directories.
    private static func makeDependencies() -> InstallDependencies {
        let environment = ProcessInfo.processInfo.environment

        #if DEBUG
        if environment["LXAI_INSTALL_SIMULATE"] == "1" {
            let root = FileManager.default.temporaryDirectory.appending(path: "lxai-install-simulation-\(UUID().uuidString)")

            if let simulated = try? InstallDependencies.simulated(root: root) {
                return simulated
            }
        }
        #endif

        var live = InstallDependencies.live()

        if environment["XCTestBundlePath"] != nil || environment["XCTestConfigurationFilePath"] != nil {
            live.checkpointStore = InMemoryInstallCheckpointStore()
            live.eventLogURL = { _ in nil }
        }

        return live
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
