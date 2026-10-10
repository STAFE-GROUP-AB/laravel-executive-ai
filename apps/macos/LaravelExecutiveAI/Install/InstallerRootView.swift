// The installer as the window's content while the install is not READY (S1-05, §12.3).
//
// Routes the machine's state and status to the six first-run screens plus the actionable error,
// cancelled and rolled-back views. Every colour, size and font is a DesignTokens value.

import DesignTokens
import SwiftUI

struct InstallerRootView: View {
    @Bindable var machine: InstallStateMachine

    var body: some View {
        Group {
            switch machine.status {
            case .cancelled:
                InstallCancelledScreen(machine: machine)
            case .rolledBack:
                InstallRolledBackScreen(machine: machine)
            case .failed(let failure):
                if case .preflightBlocked(let blockers) = failure {
                    PreflightScreen(machine: machine, blockers: blockers)
                } else {
                    InstallErrorScreen(machine: machine, failure: failure)
                }
            case .idle, .running, .waitingForPerson:
                screen(for: machine.state)
            }
        }
        .frame(minWidth: 560, minHeight: 560)
        .background(Tokens.Colors.canvas.dynamic)
        .onAppear {
            machine.start()
        }
    }

    @ViewBuilder
    private func screen(for state: InstallState) -> some View {
        switch state {
        case .welcome:
            WelcomeScreen(machine: machine)
        case .preflight:
            PreflightScreen(machine: machine, blockers: [])
        case .packageVerify, .installCore, .bootServices, .healthCheck:
            InstallProgressScreen(machine: machine)
        case .createOwner:
            CreateOwnerScreen(machine: machine)
        case .selectAI:
            ChooseAiScreen(machine: machine)
        case .ready, .connectEmail, .connectGitHub, .peekabooPermissions, .remoteAccess, .scheduleBrief:
            DoneScreen(machine: machine)
        }
    }
}

// MARK: - Shared scaffolding

/// Title, optional lead text, content and a trailing footer row — the shape every screen shares.
struct InstallScreen<Content: View, Footer: View>: View {
    let step: Int?
    let title: String
    let lead: String?
    @ViewBuilder let content: () -> Content
    @ViewBuilder let footer: () -> Footer

    init(step: Int? = nil, title: String, lead: String? = nil, @ViewBuilder content: @escaping () -> Content, @ViewBuilder footer: @escaping () -> Footer) {
        self.step = step
        self.title = title
        self.lead = lead
        self.content = content
        self.footer = footer
    }

    var body: some View {
        VStack(alignment: .leading, spacing: Tokens.Spacing.s5) {
            VStack(alignment: .leading, spacing: Tokens.Spacing.s2) {
                if let step {
                    Text("STEP \(step) OF 6")
                        .font(Tokens.Typography.label)
                        .tracking(Tokens.Typography.labelTracking)
                        .foregroundStyle(Tokens.Colors.textMuted.dynamic)
                }

                Text(title)
                    .font(Tokens.Typography.display)
                    .foregroundStyle(Tokens.Colors.textPrimary.dynamic)

                if let lead {
                    Text(lead)
                        .font(Tokens.Typography.body)
                        .foregroundStyle(Tokens.Colors.textSecondary.dynamic)
                        .fixedSize(horizontal: false, vertical: true)
                }
            }

            ScrollView(.vertical) {
                content()
                    .frame(maxWidth: .infinity, alignment: .topLeading)
            }
            .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .topLeading)

            HStack(spacing: Tokens.Spacing.s3) {
                footer()
            }
            .frame(maxWidth: .infinity, alignment: .trailing)
        }
        .padding(Tokens.Spacing.s8)
    }
}

struct InstallCard<Content: View>: View {
    var tint: Tokens.ThemedColor = Tokens.Colors.surface
    var border: Tokens.ThemedColor = Tokens.Colors.border
    @ViewBuilder let content: () -> Content

    var body: some View {
        VStack(alignment: .leading, spacing: Tokens.Spacing.s2) {
            content()
        }
        .padding(Tokens.Spacing.s4)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(tint.dynamic)
        .clipShape(RoundedRectangle(cornerRadius: Tokens.Radius.md))
        .overlay(RoundedRectangle(cornerRadius: Tokens.Radius.md).stroke(border.dynamic))
        .elevation(Tokens.Elevation.level1)
    }
}

/// Primary call to action; disables itself while the machine runs so a double click cannot
/// submit twice.
struct PrimaryInstallButton: View {
    let title: String
    var enabled = true
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            Text(title)
                .font(Tokens.Typography.bodyEmphasis)
                .padding(.horizontal, Tokens.Spacing.s5)
                .frame(minHeight: Tokens.Sizing.controlMedium)
        }
        .buttonStyle(.borderedProminent)
        .tint(Tokens.Colors.accent.dynamic)
        .keyboardShortcut(.defaultAction)
        .disabled(!enabled)
    }
}

struct SecondaryInstallButton: View {
    let title: String
    var enabled = true
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            Text(title)
                .font(Tokens.Typography.body)
                .padding(.horizontal, Tokens.Spacing.s3)
                .frame(minHeight: Tokens.Sizing.controlMedium)
        }
        .buttonStyle(.bordered)
        .disabled(!enabled)
    }
}

/// A row in a checklist: status glyph, label, optional detail. Colour is never the only signal.
struct InstallStatusRow: View {
    enum Mark {
        case pending, running, done, failed, warning
    }

    let mark: Mark
    let label: String
    var detail: String?

    var body: some View {
        HStack(alignment: .firstTextBaseline, spacing: Tokens.Spacing.s3) {
            glyph
                .frame(width: Tokens.Spacing.s5, alignment: .center)

            VStack(alignment: .leading, spacing: Tokens.Spacing.s1) {
                Text(label)
                    .font(mark == .running ? Tokens.Typography.bodyEmphasis : Tokens.Typography.body)
                    .foregroundStyle(mark == .pending ? Tokens.Colors.textMuted.dynamic : Tokens.Colors.textPrimary.dynamic)

                if let detail {
                    Text(detail)
                        .font(Tokens.Typography.footnote)
                        .foregroundStyle(Tokens.Colors.textSecondary.dynamic)
                        .fixedSize(horizontal: false, vertical: true)
                }
            }
        }
        .accessibilityElement(children: .combine)
        .accessibilityLabel("\(accessibilityPrefix) \(label)")
    }

    @ViewBuilder
    private var glyph: some View {
        switch mark {
        case .pending:
            Image(systemName: "circle").foregroundStyle(Tokens.Colors.borderStrong.dynamic)
        case .running:
            ProgressView().controlSize(.small)
        case .done:
            Image(systemName: "checkmark.circle.fill").foregroundStyle(Tokens.Colors.success.dynamic)
        case .failed:
            Image(systemName: "xmark.octagon.fill").foregroundStyle(Tokens.Colors.danger.dynamic)
        case .warning:
            Image(systemName: "exclamationmark.triangle.fill").foregroundStyle(Tokens.Colors.warning.dynamic)
        }
    }

    private var accessibilityPrefix: String {
        switch mark {
        case .pending: return "Pending:"
        case .running: return "In progress:"
        case .done: return "Done:"
        case .failed: return "Failed:"
        case .warning: return "Warning:"
        }
    }
}
