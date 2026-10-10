// Settings → Uninstall (S1-13; blueprint §1.1 lists Uninstall as a Settings surface, §12.2
// demands an explicit keep-or-delete choice for user data).
//
// `SettingsView` is the `Settings` scene's content: the placeholder for the sections still to
// come, and the Uninstall section whose "Uninstall…" button opens `UninstallConfirmationView` as
// a sheet. The sheet offers exactly three ways out — Keep my data, Delete everything, Cancel —
// and nothing runs until "Uninstall" is clicked with a choice made. The work itself goes through
// `ServiceControlling.uninstall(keepingData:)`, the same port the panel uses for "Restart
// services", so a test can prove which choice reached it. Every colour, size and font is a
// DesignTokens value; every string is a `MenuBarCopy` entry (EN/SV).

import DesignTokens
import Observation
import SwiftUI

@MainActor
@Observable
final class UninstallModel {
    enum Phase: Equatable {
        case choosing
        case running
        case done(UninstallReport)
        case failed(String)
    }

    var choice: UninstallDataChoice = .keep
    private(set) var phase: Phase = .choosing

    let serviceControl: any ServiceControlling
    let terminator: any AppTerminating

    init(serviceControl: any ServiceControlling, terminator: any AppTerminating) {
        self.serviceControl = serviceControl
        self.terminator = terminator
    }

    var isRunning: Bool {
        phase == .running
    }

    /// The one destructive entry point. Retry-safe: a failed run leaves the journal behind and
    /// the next call continues where it stopped (Uninstaller.swift).
    func confirm() async {
        guard !isRunning else {
            return
        }

        phase = .running

        do {
            phase = .done(try await serviceControl.uninstall(keepingData: choice.preservesData))
        } catch let error as ServiceControlError {
            switch error {
            case .unavailable(let reason), .uninstallFailed(let reason):
                phase = .failed(reason)
            }
        } catch {
            phase = .failed(MenuBarCopy.uninstallFailedDetail)
        }
    }

    func backToChoice() {
        guard !isRunning else {
            return
        }

        phase = .choosing
    }

    /// After a finished uninstall the app runs from the Trash; quitting is the last step.
    func quit() {
        terminator.terminateUI()
    }
}

// MARK: - Settings window

struct SettingsView: View {
    @State private var uninstall: UninstallModel
    @State private var showingUninstall = false

    init(model: PanelModel) {
        _uninstall = State(initialValue: UninstallModel(serviceControl: model.ports.serviceControl, terminator: model.ports.terminator))
    }

    var body: some View {
        VStack(alignment: .leading, spacing: Tokens.Spacing.s4) {
            Text(MenuBarCopy.settingsPlaceholderTitle)
                .font(Tokens.Typography.titleLarge)
                .foregroundStyle(Tokens.Colors.textPrimary.dynamic)

            Text(MenuBarCopy.settingsPlaceholderBody)
                .font(Tokens.Typography.body)
                .foregroundStyle(Tokens.Colors.textSecondary.dynamic)
                .fixedSize(horizontal: false, vertical: true)

            Divider()

            SectionLabel(MenuBarCopy.settingsUninstallSection)

            Text(MenuBarCopy.settingsUninstallBody)
                .font(Tokens.Typography.footnote)
                .foregroundStyle(Tokens.Colors.textSecondary.dynamic)
                .fixedSize(horizontal: false, vertical: true)

            Button(MenuBarCopy.uninstall) {
                uninstall.backToChoice()
                showingUninstall = true
            }
            .buttonStyle(.bordered)
            .tint(Tokens.Colors.danger.dynamic)
        }
        .padding(Tokens.Spacing.s6)
        .frame(width: 480, alignment: .topLeading)
        .background(Tokens.Colors.canvas.dynamic)
        .sheet(isPresented: $showingUninstall) {
            UninstallConfirmationView(model: uninstall) {
                showingUninstall = false
            }
        }
    }
}

// MARK: - Confirmation sheet

struct UninstallConfirmationView: View {
    @Bindable var model: UninstallModel
    let onCancel: () -> Void

    var body: some View {
        VStack(alignment: .leading, spacing: Tokens.Spacing.s4) {
            HStack(spacing: Tokens.Spacing.s2) {
                Image(systemName: "trash")
                    .foregroundStyle(Tokens.Colors.danger.dynamic)

                Text(title)
                    .font(Tokens.Typography.title)
                    .foregroundStyle(Tokens.Colors.textPrimary.dynamic)
            }

            switch model.phase {
            case .choosing:
                choosing
            case .running:
                running
            case .done(let report):
                done(report)
            case .failed(let reason):
                failed(reason)
            }
        }
        .padding(Tokens.Spacing.s6)
        .frame(width: 480, alignment: .topLeading)
        .background(Tokens.Colors.canvas.dynamic)
    }

    private var title: String {
        switch model.phase {
        case .choosing, .running: MenuBarCopy.uninstallTitle
        case .done: MenuBarCopy.uninstallDoneTitle
        case .failed: MenuBarCopy.uninstallFailedTitle
        }
    }

    private var choosing: some View {
        VStack(alignment: .leading, spacing: Tokens.Spacing.s4) {
            Text(MenuBarCopy.uninstallIntro)
                .font(Tokens.Typography.body)
                .foregroundStyle(Tokens.Colors.textSecondary.dynamic)
                .fixedSize(horizontal: false, vertical: true)

            VStack(alignment: .leading, spacing: Tokens.Spacing.s2) {
                DataChoiceRow(choice: .keep, title: MenuBarCopy.uninstallKeepData, detail: MenuBarCopy.uninstallKeepDataDetail, selected: $model.choice)
                DataChoiceRow(choice: .delete, title: MenuBarCopy.uninstallDeleteEverything, detail: MenuBarCopy.uninstallDeleteEverythingDetail, selected: $model.choice)
            }

            HStack {
                Spacer()

                Button(MenuBarCopy.cancel, action: onCancel)
                    .buttonStyle(.bordered)
                    .keyboardShortcut(.cancelAction)

                Button(MenuBarCopy.uninstallConfirm) {
                    Task {
                        await model.confirm()
                    }
                }
                .buttonStyle(.borderedProminent)
                .tint(Tokens.Colors.danger.dynamic)
            }
        }
    }

    private var running: some View {
        VStack(alignment: .leading, spacing: Tokens.Spacing.s2) {
            HStack(spacing: Tokens.Spacing.s2) {
                ProgressView()
                    .controlSize(.small)

                Text(MenuBarCopy.uninstallRunning)
                    .font(Tokens.Typography.bodyEmphasis)
                    .foregroundStyle(Tokens.Colors.textPrimary.dynamic)
            }

            Text(MenuBarCopy.uninstallRunningDetail)
                .font(Tokens.Typography.footnote)
                .foregroundStyle(Tokens.Colors.textSecondary.dynamic)
        }
    }

    private func done(_ report: UninstallReport) -> some View {
        VStack(alignment: .leading, spacing: Tokens.Spacing.s3) {
            Text(report.dataChoice.preservesData ? MenuBarCopy.uninstallDoneKeptData : MenuBarCopy.uninstallDoneDeleted)
                .font(Tokens.Typography.body)
                .foregroundStyle(Tokens.Colors.textSecondary.dynamic)
                .fixedSize(horizontal: false, vertical: true)

            switch report.secretsSweep.status {
            case .removed:
                Text("\(MenuBarCopy.uninstallSecretsRemovedPrefix): \(report.secretsSweep.removedCount)")
                    .font(Tokens.Typography.footnote)
                    .foregroundStyle(Tokens.Colors.textMuted.dynamic)
            case .skipped:
                Text(MenuBarCopy.uninstallSecretsSkipped)
                    .font(Tokens.Typography.footnote)
                    .foregroundStyle(Tokens.Colors.warningText.dynamic)
                    .fixedSize(horizontal: false, vertical: true)
            }

            Text(report.appBundleTrashed ? MenuBarCopy.uninstallTrashed : MenuBarCopy.uninstallNotTrashed)
                .font(Tokens.Typography.bodyEmphasis)
                .foregroundStyle(Tokens.Colors.textPrimary.dynamic)
                .fixedSize(horizontal: false, vertical: true)

            HStack {
                Spacer()

                Button(MenuBarCopy.quit) {
                    model.quit()
                }
                .buttonStyle(.borderedProminent)
                .tint(Tokens.Colors.accent.dynamic)
                .keyboardShortcut(.defaultAction)
            }
        }
    }

    private func failed(_ reason: String) -> some View {
        VStack(alignment: .leading, spacing: Tokens.Spacing.s3) {
            Text(reason)
                .font(Tokens.Typography.body)
                .foregroundStyle(Tokens.Colors.dangerText.dynamic)
                .fixedSize(horizontal: false, vertical: true)

            Text(MenuBarCopy.uninstallFailedDetail)
                .font(Tokens.Typography.footnote)
                .foregroundStyle(Tokens.Colors.textSecondary.dynamic)
                .fixedSize(horizontal: false, vertical: true)

            HStack {
                Spacer()

                Button(MenuBarCopy.cancel, action: onCancel)
                    .buttonStyle(.bordered)
                    .keyboardShortcut(.cancelAction)

                Button(MenuBarCopy.uninstallRetry) {
                    Task {
                        await model.confirm()
                    }
                }
                .buttonStyle(.borderedProminent)
                .tint(Tokens.Colors.danger.dynamic)
            }
        }
    }
}

/// One of the two data choices: a radio glyph (never colour alone), title and detail.
private struct DataChoiceRow: View {
    let choice: UninstallDataChoice
    let title: String
    let detail: String
    @Binding var selected: UninstallDataChoice

    private var isSelected: Bool {
        selected == choice
    }

    var body: some View {
        Button {
            selected = choice
        } label: {
            HStack(alignment: .top, spacing: Tokens.Spacing.s2) {
                Image(systemName: isSelected ? "largecircle.fill.circle" : "circle")
                    .foregroundStyle(isSelected ? Tokens.Colors.accent.dynamic : Tokens.Colors.textMuted.dynamic)

                VStack(alignment: .leading, spacing: Tokens.Spacing.s1) {
                    Text(title)
                        .font(Tokens.Typography.bodyEmphasis)
                        .foregroundStyle(Tokens.Colors.textPrimary.dynamic)

                    Text(detail)
                        .font(Tokens.Typography.footnote)
                        .foregroundStyle(choice == .delete ? Tokens.Colors.dangerText.dynamic : Tokens.Colors.textSecondary.dynamic)
                        .fixedSize(horizontal: false, vertical: true)
                }

                Spacer(minLength: 0)
            }
            .padding(Tokens.Spacing.s3)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(isSelected ? Tokens.Colors.accentSubtle.dynamic : Tokens.Colors.surfaceRaised.dynamic, in: RoundedRectangle(cornerRadius: Tokens.Radius.sm))
            .overlay(RoundedRectangle(cornerRadius: Tokens.Radius.sm).strokeBorder(isSelected ? Tokens.Colors.accent.dynamic : Tokens.Colors.border.dynamic))
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .accessibilityAddTraits(isSelected ? [.isSelected] : [])
    }
}
