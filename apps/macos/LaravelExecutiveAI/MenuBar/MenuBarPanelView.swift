// The MenuBarExtra panel (S1-09, blueprint §1.1).
//
// Top to bottom: status (state, AI, connectivity, privacy mode), the latest brief (subject, date
// and generation time, version, every item in document order with the whole body behind a
// disclosure, sources or uncertainty marker, action affordances), tasks awaiting approval
// (read-only, deep links), the shortcuts — Open full brief, Pause/Resume AI actions, Open PWA,
// Settings, Quit UI. Every colour, size and font is a DesignTokens value.

import DesignTokens
import SwiftUI

struct MenuBarPanelView: View {
    @Bindable var model: PanelModel
    /// The live panel scrolls its middle section; the documentation renderer lays it out in full.
    var scrolls = true

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            StatusSection(model: model)

            Divider()

            if scrolls {
                ScrollView {
                    content
                }
                .frame(maxHeight: 420)
            } else {
                content
            }

            Divider()

            ShortcutsSection(model: model)
        }
        .frame(width: Tokens.Sizing.menuBarPanelWidth)
        .background(Tokens.Colors.surface.dynamic)
        .onAppear {
            model.openPanel()
        }
        .onDisappear {
            model.closePanel()
        }
    }

    private var content: some View {
        VStack(alignment: .leading, spacing: Tokens.Spacing.s3) {
            BriefSection(model: model)

            if !model.approvals.isEmpty || model.badgeCount > 0 {
                ApprovalsSection(model: model)
            }
        }
        .padding(Tokens.Spacing.s3)
        .frame(maxWidth: .infinity, alignment: .leading)
    }
}

// MARK: - Status

private struct StatusSection: View {
    let model: PanelModel

    var body: some View {
        VStack(alignment: .leading, spacing: Tokens.Spacing.s1) {
            HStack(spacing: Tokens.Spacing.s2) {
                Image(systemName: model.state.systemImage)
                    .foregroundStyle(stateColor)

                Text(model.state.title)
                    .font(Tokens.Typography.headline)
                    .foregroundStyle(Tokens.Colors.textPrimary.dynamic)

                Spacer()

                if model.badgeCount > 0 {
                    Text("\(model.badgeCount)")
                        .font(Tokens.Typography.label)
                        .foregroundStyle(Tokens.Colors.textOnAccent.dynamic)
                        .padding(.horizontal, Tokens.Spacing.s2)
                        .padding(.vertical, Tokens.Spacing.s1)
                        .background(Tokens.Colors.accent.dynamic, in: Capsule())
                        .accessibilityLabel(MenuBarCopy.badgeAccessibilityLabel(count: model.badgeCount))
                }
            }

            if !model.state.detail.isEmpty {
                Text(model.state.detail)
                    .font(Tokens.Typography.footnote)
                    .foregroundStyle(model.state.isError ? Tokens.Colors.dangerText.dynamic : Tokens.Colors.textSecondary.dynamic)
                    .fixedSize(horizontal: false, vertical: true)
            }

            HStack(alignment: .top, spacing: Tokens.Spacing.s2) {
                StatusChip(text: model.aiStatusText, tone: aiTone)

                if let note = model.connectivityNote {
                    StatusChip(text: note, tone: .warning)
                }
            }
            .padding(.top, Tokens.Spacing.s1)

            AiRowDetails(model: model)

            if let mode = model.privacyMode {
                Text("\(MenuBarCopy.privacyModePrefix): \(mode)")
                    .font(Tokens.Typography.caption)
                    .foregroundStyle(Tokens.Colors.textMuted.dynamic)
            }

            if model.state.isError {
                HStack(spacing: Tokens.Spacing.s2) {
                    Button(MenuBarCopy.openDiagnostics) {
                        model.openDiagnostics()
                    }

                    Button(MenuBarCopy.restartServices) {
                        Task {
                            await model.restartServices()
                        }
                    }
                }
                .controlSize(.small)
                .padding(.top, Tokens.Spacing.s1)
            }
        }
        .padding(Tokens.Spacing.s3)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(stateSurface)
    }

    private var stateColor: Color {
        switch model.state {
        case .fresh: Tokens.Colors.success.dynamic
        case .stale, .aiSetupRequired: Tokens.Colors.warning.dynamic
        case .error, .coreUnreachable: Tokens.Colors.danger.dynamic
        case .loading: Tokens.Colors.textMuted.dynamic
        }
    }

    private var stateSurface: Color {
        switch model.state {
        case .fresh, .loading: Tokens.Colors.surfaceSunken.dynamic
        case .stale, .aiSetupRequired: Tokens.Colors.warningSurface.dynamic
        case .error, .coreUnreachable: Tokens.Colors.dangerSurface.dynamic
        }
    }

    private var aiTone: StatusChip.Tone {
        if model.aiActionsPaused {
            return .warning
        }

        switch model.health?.aiState {
        case .ready where model.health?.aiReady == true: return .success
        case .error: return .danger
        default: return .warning
        }
    }
}

/// The AI row's details (S1-11, menubar.md §8): provider, the recorded last success while AI is
/// ready, and the one action — "Set up AI" or "Reconfigure AI". Never a second "ready" claim.
private struct AiRowDetails: View {
    let model: PanelModel

    var body: some View {
        VStack(alignment: .leading, spacing: Tokens.Spacing.s1) {
            if let provider = model.aiProviderText {
                Text(model.aiProviderNote.map { "\(MenuBarCopy.aiProviderPrefix): \(provider) — \($0)" } ?? "\(MenuBarCopy.aiProviderPrefix): \(provider)")
                    .font(Tokens.Typography.caption)
                    .foregroundStyle(Tokens.Colors.textSecondary.dynamic)
                    .fixedSize(horizontal: false, vertical: true)
            }

            if let lastSuccess = model.aiLastSuccessText {
                Text("\(MenuBarCopy.aiLastSuccessPrefix): \(lastSuccess)")
                    .font(Tokens.Typography.caption)
                    .foregroundStyle(Tokens.Colors.textMuted.dynamic)
            }

            if let action = model.aiAction {
                Button(action.title) {
                    model.reconfigureAI()
                }
                .controlSize(.small)
                .padding(.top, Tokens.Spacing.s1)
            }
        }
    }
}

private struct StatusChip: View {
    enum Tone {
        case neutral, success, warning, danger
    }

    let text: String
    let tone: Tone

    var body: some View {
        Text(text)
            .font(Tokens.Typography.caption)
            .foregroundStyle(foreground)
            .padding(.horizontal, Tokens.Spacing.s2)
            .padding(.vertical, Tokens.Spacing.s1)
            .background(background, in: RoundedRectangle(cornerRadius: Tokens.Radius.xs))
            .fixedSize(horizontal: false, vertical: true)
    }

    private var foreground: Color {
        switch tone {
        case .neutral: Tokens.Colors.textSecondary.dynamic
        case .success: Tokens.Colors.successText.dynamic
        case .warning: Tokens.Colors.warningText.dynamic
        case .danger: Tokens.Colors.dangerText.dynamic
        }
    }

    private var background: Color {
        switch tone {
        case .neutral: Tokens.Colors.surfaceRaised.dynamic
        case .success: Tokens.Colors.successSurface.dynamic
        case .warning: Tokens.Colors.warningSurface.dynamic
        case .danger: Tokens.Colors.dangerSurface.dynamic
        }
    }
}

// MARK: - Brief

private struct BriefSection: View {
    let model: PanelModel

    var body: some View {
        VStack(alignment: .leading, spacing: Tokens.Spacing.s2) {
            SectionLabel(MenuBarCopy.latestBrief)

            if let brief = model.brief {
                Text(brief.subject)
                    .font(Tokens.Typography.bodyEmphasis)
                    .foregroundStyle(Tokens.Colors.textPrimary.dynamic)
                    .fixedSize(horizontal: false, vertical: true)

                HStack(spacing: Tokens.Spacing.s2) {
                    if let generated = model.generatedAtText {
                        Text("\(MenuBarCopy.generatedPrefix) \(RFC3339.display(generated))")
                    }

                    if let version = model.latestBriefVersion {
                        Text("\(MenuBarCopy.versionPrefix)\(version)")
                    }

                    if model.briefStateIsStale {
                        Text(MenuBarCopy.stateStale)
                            .foregroundStyle(Tokens.Colors.warningText.dynamic)
                    }
                }
                .font(Tokens.Typography.footnote)
                .foregroundStyle(Tokens.Colors.textMuted.dynamic)

                Text(brief.summary)
                    .font(Tokens.Typography.footnote)
                    .foregroundStyle(Tokens.Colors.textSecondary.dynamic)
                    .fixedSize(horizontal: false, vertical: true)

                if !brief.items.isEmpty {
                    SectionLabel(MenuBarCopy.briefItems)
                        .padding(.top, Tokens.Spacing.s1)

                    ForEach(brief.items, id: \.id) { item in
                        BriefItemRow(item: item, model: model)
                    }
                }
            } else {
                Text(MenuBarCopy.noBriefYet)
                    .font(Tokens.Typography.bodyEmphasis)
                    .foregroundStyle(Tokens.Colors.textPrimary.dynamic)

                if let generated = model.generatedAtText {
                    Text("\(MenuBarCopy.generatedPrefix) \(RFC3339.display(generated))")
                        .font(Tokens.Typography.footnote)
                        .foregroundStyle(Tokens.Colors.textMuted.dynamic)
                }
            }
        }
    }
}

private struct BriefItemRow: View {
    let item: BriefParityPayload.Item
    let model: PanelModel

    @State private var expanded = false

    var body: some View {
        DisclosureGroup(isExpanded: $expanded) {
            VStack(alignment: .leading, spacing: Tokens.Spacing.s2) {
                Text(item.body)
                    .font(Tokens.Typography.footnote)
                    .foregroundStyle(Tokens.Colors.textSecondary.dynamic)
                    .fixedSize(horizontal: false, vertical: true)
                    .textSelection(.enabled)

                if let due = item.dueAt {
                    Text("\(MenuBarCopy.duePrefix) \(RFC3339.display(due))")
                        .font(Tokens.Typography.caption)
                        .foregroundStyle(Tokens.Colors.warningText.dynamic)
                }

                if !item.sourceRefs.isEmpty {
                    SectionLabel(MenuBarCopy.sources)

                    ForEach(item.sourceRefs, id: \.id) { ref in
                        SourceRefRow(ref: ref, model: model)
                    }
                }

                if let uncertainty = item.uncertainty {
                    HStack(alignment: .top, spacing: Tokens.Spacing.s1) {
                        Image(systemName: "questionmark.circle")
                            .foregroundStyle(Tokens.Colors.warning.dynamic)

                        Text("\(MenuBarCopy.uncertaintyPrefix) (\(MenuBarCopy.confidencePrefix) \(uncertainty.confidence.formatted(.percent))): \(uncertainty.reason)")
                            .font(Tokens.Typography.caption)
                            .foregroundStyle(Tokens.Colors.warningText.dynamic)
                            .fixedSize(horizontal: false, vertical: true)
                    }
                }

                ForEach(item.actionIds, id: \.self) { actionId in
                    Button {
                        model.openAction(id: actionId)
                    } label: {
                        Label(MenuBarCopy.reviewAction, systemImage: "checkmark.seal")
                    }
                    .controlSize(.small)
                }
            }
            .padding(.top, Tokens.Spacing.s1)
        } label: {
            HStack(alignment: .firstTextBaseline, spacing: Tokens.Spacing.s2) {
                Text(MenuBarCopy.priorityLabel(item.priority))
                    .font(Tokens.Typography.label)
                    .foregroundStyle(priorityColor)
                    .frame(width: 52, alignment: .leading)

                Text(item.title)
                    .font(Tokens.Typography.body)
                    .foregroundStyle(Tokens.Colors.textPrimary.dynamic)
                    .fixedSize(horizontal: false, vertical: true)

                Spacer(minLength: 0)

                Text(MenuBarCopy.kindLabel(item.kind))
                    .font(Tokens.Typography.caption)
                    .foregroundStyle(Tokens.Colors.textMuted.dynamic)

                if !item.actionIds.isEmpty {
                    Image(systemName: "checkmark.seal")
                        .foregroundStyle(Tokens.Colors.accent.dynamic)
                        .accessibilityLabel(MenuBarCopy.reviewAction)
                }
            }
        }
    }

    private var priorityColor: Color {
        switch item.priority {
        case "critical": Tokens.Colors.danger.dynamic
        case "high": Tokens.Colors.warning.dynamic
        default: Tokens.Colors.textMuted.dynamic
        }
    }
}

private struct SourceRefRow: View {
    let ref: BriefParityPayload.SourceRef
    let model: PanelModel

    var body: some View {
        HStack(alignment: .firstTextBaseline, spacing: Tokens.Spacing.s1) {
            Text(MenuBarCopy.sourceTypeLabel(ref.type))
                .font(Tokens.Typography.caption)
                .foregroundStyle(Tokens.Colors.textMuted.dynamic)

            if let account = ref.accountLabel {
                Text("· \(account)")
                    .font(Tokens.Typography.caption)
                    .foregroundStyle(Tokens.Colors.textMuted.dynamic)
            }

            if let title = ref.title {
                Text("· \(title)")
                    .font(Tokens.Typography.caption)
                    .foregroundStyle(Tokens.Colors.textSecondary.dynamic)
            }
        }
        .fixedSize(horizontal: false, vertical: true)
        .help(ref.uri)
    }
}

// MARK: - Approvals (read-only, deep links only)

private struct ApprovalsSection: View {
    let model: PanelModel

    var body: some View {
        VStack(alignment: .leading, spacing: Tokens.Spacing.s2) {
            SectionLabel(MenuBarCopy.approvalsTitle(count: model.badgeCount))

            if model.approvals.isEmpty {
                Text(MenuBarCopy.noApprovals)
                    .font(Tokens.Typography.footnote)
                    .foregroundStyle(Tokens.Colors.textMuted.dynamic)
            }

            ForEach(model.approvals) { approval in
                HStack(alignment: .top, spacing: Tokens.Spacing.s2) {
                    VStack(alignment: .leading, spacing: Tokens.Spacing.s1) {
                        Text(approval.title)
                            .font(Tokens.Typography.body)
                            .foregroundStyle(Tokens.Colors.textPrimary.dynamic)
                            .fixedSize(horizontal: false, vertical: true)

                        Text("\(MenuBarCopy.approvalKindLabel(approval.kind)) · \(approval.summary)")
                            .font(Tokens.Typography.caption)
                            .foregroundStyle(Tokens.Colors.textMuted.dynamic)
                            .fixedSize(horizontal: false, vertical: true)
                    }

                    Spacer(minLength: 0)

                    Button(MenuBarCopy.open) {
                        model.openApproval(approval)
                    }
                    .controlSize(.small)
                }
                .padding(Tokens.Spacing.s2)
                .background(Tokens.Colors.accentSubtle.dynamic, in: RoundedRectangle(cornerRadius: Tokens.Radius.sm))
            }
        }
    }
}

// MARK: - Shortcuts (§1.1)

private struct ShortcutsSection: View {
    let model: PanelModel

    var body: some View {
        VStack(alignment: .leading, spacing: Tokens.Spacing.s1) {
            ShortcutButton(MenuBarCopy.openFullBrief, systemImage: "doc.text") {
                model.openFullBrief()
            }
            .keyboardShortcut("b")

            ShortcutButton(model.aiActionsPaused ? MenuBarCopy.resumeAI : MenuBarCopy.pauseAI,
                           systemImage: model.aiActionsPaused ? "play.circle" : "pause.circle") {
                Task {
                    await model.togglePauseAI()
                }
            }
            .keyboardShortcut("p")

            if model.pauseFailed {
                Text(MenuBarCopy.pauseFailed)
                    .font(Tokens.Typography.caption)
                    .foregroundStyle(Tokens.Colors.dangerText.dynamic)
                    .padding(.leading, Tokens.Spacing.s6)
            }

            ShortcutButton(MenuBarCopy.openPWA, systemImage: "safari") {
                model.openPWA()
            }
            .keyboardShortcut("o")

            ShortcutButton(MenuBarCopy.settings, systemImage: "gearshape") {
                model.openSettings()
            }
            .keyboardShortcut(",")

            Divider()
                .padding(.vertical, Tokens.Spacing.s1)

            ShortcutButton(MenuBarCopy.quitUI, systemImage: "power") {
                model.quitUI()
            }
            .keyboardShortcut("q")
            .help(MenuBarCopy.quitUIHint)

            Text(MenuBarCopy.quitUIHint)
                .font(Tokens.Typography.caption)
                .foregroundStyle(Tokens.Colors.textMuted.dynamic)
                .padding(.leading, Tokens.Spacing.s6)
        }
        .padding(Tokens.Spacing.s3)
    }
}

private struct ShortcutButton: View {
    let title: String
    let systemImage: String
    let action: () -> Void

    init(_ title: String, systemImage: String, action: @escaping () -> Void) {
        self.title = title
        self.systemImage = systemImage
        self.action = action
    }

    var body: some View {
        Button(action: action) {
            Label(title, systemImage: systemImage)
                .font(Tokens.Typography.body)
                .foregroundStyle(Tokens.Colors.textPrimary.dynamic)
                .frame(maxWidth: .infinity, alignment: .leading)
                .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .padding(.vertical, Tokens.Spacing.s1)
    }
}

/// Shared with the Settings window (UninstallView.swift).
struct SectionLabel: View {
    let text: String

    init(_ text: String) {
        self.text = text
    }

    var body: some View {
        Text(text.uppercased())
            .font(Tokens.Typography.label)
            .tracking(Tokens.Typography.labelTracking)
            .foregroundStyle(Tokens.Colors.textMuted.dynamic)
    }
}
