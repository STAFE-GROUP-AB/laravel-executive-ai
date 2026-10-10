// The six first-run screens (§12.3) and the three outcome views (error, cancelled, rolled back).
//
// Mandatory interactions on the happy path: Welcome CTA, the owner form, the AI choice, and
// "Open" on Done — four, within the §12.3 limit of six. No screen shows a shell command.

import DesignTokens
import SwiftUI

// MARK: - 1. Welcome

struct WelcomeScreen: View {
    @Bindable var machine: InstallStateMachine

    var body: some View {
        InstallScreen(
            step: 1,
            title: "Laravel Executive AI",
            lead: "A self-hosted AI secretary that lives on this Mac. Your mail, customers and briefs stay here; nothing leaves unless you connect it."
        ) {
            VStack(alignment: .leading, spacing: Tokens.Spacing.s3) {
                InstallCard {
                    Label("Everything needed is inside this app — no Terminal, no package managers.", systemImage: "shippingbox")
                    Label("The install takes a few minutes and asks you three questions.", systemImage: "clock")
                    Label("Optional connections (e-mail, GitHub, remote access) come after, at your pace.", systemImage: "link")
                }
                .font(Tokens.Typography.body)
                .foregroundStyle(Tokens.Colors.textSecondary.dynamic)

                if machine.canResume {
                    InstallCard(tint: Tokens.Colors.infoSurface, border: Tokens.Colors.info) {
                        Label("An earlier install was interrupted. It continues from where it stopped.", systemImage: "arrow.uturn.forward")
                            .font(Tokens.Typography.body)
                            .foregroundStyle(Tokens.Colors.infoText.dynamic)
                    }
                }
            }
        } footer: {
            PrimaryInstallButton(title: machine.canResume ? "Continue installation" : "Install", enabled: machine.status == .waitingForPerson) {
                Task { await machine.proceedFromWelcome() }
            }
        }
    }
}

// MARK: - 2. Check (blockers only)

struct PreflightScreen: View {
    @Bindable var machine: InstallStateMachine
    let blockers: [PreflightBlocker]

    var body: some View {
        InstallScreen(
            step: 2,
            title: blockers.isEmpty ? "Checking this Mac" : "Before installing",
            lead: blockers.isEmpty
                ? "macOS version, chip, memory, free space (about 2 GB for the core), permissions, ports and the app's own signature."
                : nil
        ) {
            if blockers.isEmpty {
                InstallCard {
                    InstallStatusRow(mark: .running, label: "Running \(PreflightCheckID.allCases.count) checks")
                }
            } else {
                ScrollView {
                    VStack(alignment: .leading, spacing: Tokens.Spacing.s3) {
                        ForEach(Array(blockers.enumerated()), id: \.element.id) { index, blocker in
                            InstallCard(tint: Tokens.Colors.dangerSurface, border: Tokens.Colors.danger) {
                                Text(blocker.message.headline(machine.language))
                                    .font(Tokens.Typography.headline)
                                    .foregroundStyle(Tokens.Colors.dangerText.dynamic)
                                    .fixedSize(horizontal: false, vertical: true)

                                let action = blocker.message.action(machine.language)

                                if !action.isEmpty {
                                    Text(action)
                                        .font(index == 0 ? Tokens.Typography.bodyEmphasis : Tokens.Typography.body)
                                        .foregroundStyle(Tokens.Colors.textPrimary.dynamic)
                                        .fixedSize(horizontal: false, vertical: true)
                                }
                            }
                        }
                    }
                }
            }
        } footer: {
            if !blockers.isEmpty {
                SecondaryInstallButton(title: InstallAction.cancel.label) {
                    Task { await machine.cancel() }
                }

                PrimaryInstallButton(title: "Check again") {
                    Task { await machine.retry() }
                }
            }
        }
    }
}

// MARK: - 3. Install (real checkpoints)

struct InstallProgressScreen: View {
    @Bindable var machine: InstallStateMachine

    private let automatedStates: [InstallState] = [.packageVerify, .installCore, .bootServices, .healthCheck]

    var body: some View {
        InstallScreen(
            step: 3,
            title: machine.state.title,
            lead: "Each step below is a real checkpoint. Cancel at any time; nothing half-done is left behind."
        ) {
            VStack(alignment: .leading, spacing: Tokens.Spacing.s3) {
                InstallCard {
                    ForEach(automatedStates, id: \.self) { state in
                        InstallStatusRow(mark: mark(for: state), label: state.title, detail: detail(for: state))
                    }
                }

                if machine.state == .installCore, let checkpoint = machine.coreCheckpoint {
                    ProgressView(value: checkpoint.fraction)
                        .tint(Tokens.Colors.accent.dynamic)
                        .accessibilityLabel("Runtime install progress")
                }
            }
        } footer: {
            SecondaryInstallButton(title: InstallAction.cancel.label, enabled: machine.status == .running) {
                Task { await machine.cancel() }
            }
        }
    }

    private func mark(for state: InstallState) -> InstallStatusRow.Mark {
        guard let index = state.coreIndex, let current = machine.state.coreIndex else {
            return .pending
        }

        if index < current {
            return .done
        }

        if index == current {
            return machine.status == .running ? .running : .done
        }

        return .pending
    }

    private func detail(for state: InstallState) -> String? {
        guard state == machine.state else {
            return nil
        }

        switch state {
        case .packageVerify:
            return "Checksums and the developer signature of the bundled runtime, before anything runs."
        case .installCore:
            return machine.coreCheckpoint?.label ?? "Preparing"
        case .bootServices:
            return "Registering the background service. macOS may ask you to allow it in Login Items — no password."
        case .healthCheck:
            return machine.healthAttempts > 1
                ? "Waiting for the core to report ready (attempt \(machine.healthAttempts))"
                : "Waiting for the core to report ready"
        default:
            return nil
        }
    }
}

// MARK: - 4. Create account

struct CreateOwnerScreen: View {
    @Bindable var machine: InstallStateMachine
    @State private var draft = OwnerDraft()
    @State private var showErrors = false

    private var errors: [OwnerDraft.ValidationError] {
        draft.validate()
    }

    var body: some View {
        InstallScreen(
            step: 4,
            title: "Create your account",
            lead: "You are the owner. This account lives only on this Mac; a passkey or password is set up when you first open the app."
        ) {
            VStack(alignment: .leading, spacing: Tokens.Spacing.s4) {
                InstallCard {
                    field("Name", text: $draft.name, prompt: "Your name", error: showErrors && errors.contains(.nameMissing) ? "Enter your name." : nil)
                    field("E-mail", text: $draft.email, prompt: "you@example.com", error: emailError)
                }

                Text("The address identifies your account and receives the Executive Brief. It is not sent anywhere else.")
                    .font(Tokens.Typography.footnote)
                    .foregroundStyle(Tokens.Colors.textMuted.dynamic)
            }
        } footer: {
            SecondaryInstallButton(title: InstallAction.cancel.label, enabled: machine.status == .waitingForPerson) {
                Task { await machine.cancel() }
            }

            PrimaryInstallButton(title: "Continue", enabled: machine.status == .waitingForPerson) {
                showErrors = true

                guard draft.isValid else {
                    return
                }

                Task { await machine.submitOwner(draft) }
            }
        }
    }

    private var emailError: String? {
        guard showErrors else {
            return nil
        }

        if errors.contains(.emailMissing) {
            return "Enter your e-mail address."
        }

        if errors.contains(.emailInvalid) {
            return "That does not look like an e-mail address."
        }

        return nil
    }

    @ViewBuilder
    private func field(_ label: String, text: Binding<String>, prompt: String, error: String?) -> some View {
        VStack(alignment: .leading, spacing: Tokens.Spacing.s1) {
            Text(label)
                .font(Tokens.Typography.label)
                .tracking(Tokens.Typography.labelTracking)
                .foregroundStyle(Tokens.Colors.textMuted.dynamic)

            TextField(label, text: text, prompt: Text(prompt))
                .textFieldStyle(.roundedBorder)
                .font(Tokens.Typography.body)
                .autocorrectionDisabled()

            if let error {
                Text(error)
                    .font(Tokens.Typography.footnote)
                    .foregroundStyle(Tokens.Colors.dangerText.dynamic)
            }
        }
        .padding(.bottom, Tokens.Spacing.s2)
    }
}

// MARK: - 5. Choose AI

struct ChooseAiScreen: View {
    private enum Choice: Hashable {
        case skip
        case local(LocalAiRuntime)
        case ownKey
    }

    @Bindable var machine: InstallStateMachine
    @State private var choice: Choice? = nil
    @State private var provider: AiProviderID = .openAI
    @State private var keyText = ""
    @State private var showErrors = false

    var body: some View {
        InstallScreen(
            step: 5,
            title: "Choose AI",
            lead: "Pick what the assistant thinks with. You can change this later, and nothing here is required to use the CRM or read briefs."
        ) {
            ScrollView {
                VStack(alignment: .leading, spacing: Tokens.Spacing.s3) {
                    option(.skip, title: "Skip for now", detail: "Install without AI. The app shows “AI setup required” until you choose one.")

                    ForEach(machine.detectedRuntimes) { runtime in
                        option(.local(runtime), title: "Use \(runtime.displayName) on this Mac", detail: "Already installed here. Models run locally; nothing is sent to a provider.")
                    }

                    if machine.detectedRuntimes.isEmpty {
                        Text("No local inference runtime was found on this Mac. You can install one later and switch.")
                            .font(Tokens.Typography.footnote)
                            .foregroundStyle(Tokens.Colors.textMuted.dynamic)
                    }

                    option(.ownKey, title: "Use my own API key", detail: "Your key is stored in the macOS Keychain by the background service, never in a file.")

                    if choice == .ownKey {
                        keyFields
                    }
                }
            }
        } footer: {
            SecondaryInstallButton(title: InstallAction.cancel.label, enabled: machine.status == .waitingForPerson) {
                Task { await machine.cancel() }
            }

            PrimaryInstallButton(title: "Continue", enabled: machine.status == .waitingForPerson && choice != nil) {
                showErrors = true

                guard let submission else {
                    return
                }

                Task { await machine.submitAiSelection(submission) }
            }
        }
    }

    private var submission: AiSelectionSubmission? {
        switch choice {
        case nil:
            return nil
        case .skip:
            return AiSelectionSubmission(selection: .skip)
        case .local(let runtime):
            return AiSelectionSubmission(selection: .detectedLocalRuntime(runtime))
        case .ownKey:
            guard let key = TransientSecret(keyText) else {
                return nil
            }

            return AiSelectionSubmission(selection: .ownApiKey(provider), apiKey: key)
        }
    }

    @ViewBuilder
    private var keyFields: some View {
        InstallCard {
            Picker("Provider", selection: $provider) {
                ForEach(AiProviderID.allCases, id: \.self) { provider in
                    Text(provider.displayName).tag(provider)
                }
            }
            .pickerStyle(.menu)
            .font(Tokens.Typography.body)

            SecureField("API key", text: $keyText, prompt: Text("Paste the key"))
                .textFieldStyle(.roundedBorder)
                .font(Tokens.Typography.mono)

            if showErrors, TransientSecret(keyText) == nil {
                Text("Paste the key, or choose another option.")
                    .font(Tokens.Typography.footnote)
                    .foregroundStyle(Tokens.Colors.dangerText.dynamic)
            }
        }
    }

    @ViewBuilder
    private func option(_ value: Choice, title: String, detail: String) -> some View {
        let selected = choice == value

        Button {
            choice = value
        } label: {
            HStack(alignment: .top, spacing: Tokens.Spacing.s3) {
                Image(systemName: selected ? "largecircle.fill.circle" : "circle")
                    .foregroundStyle(selected ? Tokens.Colors.accent.dynamic : Tokens.Colors.borderStrong.dynamic)
                    .padding(.top, 2)

                VStack(alignment: .leading, spacing: Tokens.Spacing.s1) {
                    Text(title)
                        .font(Tokens.Typography.bodyEmphasis)
                        .foregroundStyle(Tokens.Colors.textPrimary.dynamic)

                    Text(detail)
                        .font(Tokens.Typography.footnote)
                        .foregroundStyle(Tokens.Colors.textSecondary.dynamic)
                        .fixedSize(horizontal: false, vertical: true)
                }

                Spacer(minLength: 0)
            }
            .padding(Tokens.Spacing.s4)
            .background((selected ? Tokens.Colors.accentSubtle : Tokens.Colors.surface).dynamic)
            .clipShape(RoundedRectangle(cornerRadius: Tokens.Radius.md))
            .overlay(RoundedRectangle(cornerRadius: Tokens.Radius.md).stroke((selected ? Tokens.Colors.accent : Tokens.Colors.border).dynamic))
        }
        .buttonStyle(.plain)
        .accessibilityAddTraits(selected ? .isSelected : [])
    }
}

// MARK: - 6. Done

struct DoneScreen: View {
    @Bindable var machine: InstallStateMachine
    @Environment(\.openURL) private var openURL

    private var aiSetupRequired: Bool {
        machine.lastHealth?.aiSetupRequired ?? true
    }

    var body: some View {
        InstallScreen(
            step: 6,
            title: "Ready",
            lead: "The core is running on this Mac and the menu bar app is live. Optional connections can be set up any time from the menu bar."
        ) {
            VStack(alignment: .leading, spacing: Tokens.Spacing.s3) {
                if aiSetupRequired {
                    InstallCard(tint: Tokens.Colors.warningSurface, border: Tokens.Colors.warning) {
                        Label("AI setup required", systemImage: "exclamationmark.triangle.fill")
                            .font(Tokens.Typography.headline)
                            .foregroundStyle(Tokens.Colors.warningText.dynamic)

                        Text("The CRM, previous briefs and administration work now. AI chat and new briefs start once a model or API key has completed one request.")
                            .font(Tokens.Typography.footnote)
                            .foregroundStyle(Tokens.Colors.textPrimary.dynamic)
                            .fixedSize(horizontal: false, vertical: true)
                    }
                }

                InstallCard {
                    Text("Next, when you want to")
                        .font(Tokens.Typography.label)
                        .tracking(Tokens.Typography.labelTracking)
                        .foregroundStyle(Tokens.Colors.textMuted.dynamic)

                    ForEach(InstallState.optionalPath, id: \.self) { step in
                        InstallStatusRow(mark: .pending, label: step.title)
                    }

                    if machine.checkpoint.preflight?.fileVaultEnabled == false {
                        InstallStatusRow(mark: .warning, label: "Turn on FileVault", detail: "The local database is protected by FileVault's disk encryption; it is off on this Mac.")
                    }

                    if machine.checkpoint.preflight?.fileVaultEnabled == true {
                        InstallStatusRow(mark: .done, label: "FileVault is on", detail: "Services start after you log in; scheduled briefs wait until then.")
                    }
                }
            }
        } footer: {
            PrimaryInstallButton(title: "Open Laravel Executive AI") {
                if let port = machine.checkpoint.preflight?.port, let url = URL(string: "http://127.0.0.1:\(port)/") {
                    openURL(url)
                }

                machine.acknowledgeDone()
            }
        }
    }
}

// MARK: - Outcome views

struct InstallErrorScreen: View {
    @Bindable var machine: InstallStateMachine
    let failure: InstallFailure

    var body: some View {
        InstallScreen(title: failure.title, lead: nil) {
            InstallCard(tint: failure == .loginItemApprovalRequired ? Tokens.Colors.infoSurface : Tokens.Colors.dangerSurface,
                        border: failure == .loginItemApprovalRequired ? Tokens.Colors.info : Tokens.Colors.danger) {
                Text(failure.detail)
                    .font(Tokens.Typography.body)
                    .foregroundStyle(Tokens.Colors.textPrimary.dynamic)
                    .fixedSize(horizontal: false, vertical: true)

                Text("Stopped at \(machine.state.title.lowercased()).")
                    .font(Tokens.Typography.footnote)
                    .foregroundStyle(Tokens.Colors.textMuted.dynamic)
            }
        } footer: {
            ForEach(failure.actions, id: \.self) { action in
                switch action {
                case .retry:
                    PrimaryInstallButton(title: action.label) {
                        Task { await machine.retry() }
                    }
                case .cancel:
                    SecondaryInstallButton(title: action.label) {
                        Task { await machine.cancel() }
                    }
                case .rollback:
                    SecondaryInstallButton(title: action.label) {
                        Task { await machine.rollback() }
                    }
                case .openLoginItems:
                    SecondaryInstallButton(title: action.label) {
                        machine.openLoginItems()
                    }
                }
            }
        }
    }
}

struct InstallCancelledScreen: View {
    @Bindable var machine: InstallStateMachine

    var body: some View {
        InstallScreen(title: "Installation cancelled", lead: machine.canResume
            ? "What was completed is kept. Open the app again to continue from where you stopped."
            : "Nothing was installed on this Mac."
        ) {
            EmptyView()
        } footer: {
            SecondaryInstallButton(title: "Quit") {
                NSApplication.shared.terminate(nil)
            }
        }
    }
}

struct InstallRolledBackScreen: View {
    @Bindable var machine: InstallStateMachine

    var body: some View {
        InstallScreen(title: "Partial install removed", lead: "The runtime files copied so far were deleted. You can try the install again or cancel.") {
            EmptyView()
        } footer: {
            SecondaryInstallButton(title: InstallAction.cancel.label) {
                Task { await machine.cancel() }
            }

            PrimaryInstallButton(title: InstallAction.retry.label) {
                Task { await machine.retry() }
            }
        }
    }
}
