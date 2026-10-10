// Every user-visible string of the menu bar panel, in one place (S1-09, S1-11; blueprint §1.1).
//
// English and Swedish side by side in `MenuBarStrings`; `MenuBarCopy` picks the table for the
// current language (system language, EN default; SV when it is Swedish; tests and a future
// Settings › Language switch override it with `use(_:)`). "AI ready" / "AI redo" is the single
// `aiReady` entry and `PanelModel.aiStatusText` is the single code path that emits it
// (MenuBarAiReadyGateTests). Nothing here interpolates a token, a pairing grant, a health `detail`
// or raw mail/CRM text: the only dynamic parts are counts, dates, check names, the provider's
// display name and the brief's own content (§11; threat model T-33/T-34). MenuBarCopyTests greps
// every string of both languages for `lxai1.`, `Bearer` and `signing_key`.

import Foundation
import os

enum MenuBarLanguage: String, CaseIterable, Sendable {
    case en
    case sv

    /// Swedish when the locale's language is `sv`; English for everything else (support matrix §6).
    static func preferred(from locale: Locale = .current) -> MenuBarLanguage {
        locale.language.languageCode?.identifier.lowercased() == "sv" ? .sv : .en
    }
}

enum MenuBarCopy {
    private static let override = OSAllocatedUnfairLock<MenuBarLanguage?>(initialState: nil)

    static var language: MenuBarLanguage {
        override.withLock { $0 } ?? .preferred()
    }

    /// Pins the language; `nil` returns to the system language.
    static func use(_ language: MenuBarLanguage?) {
        override.withLock { $0 = language }
    }

    static var strings: MenuBarStrings {
        language == .sv ? .sv : .en
    }

    static var appName: String { strings.appName }

    // Section labels
    static var latestBrief: String { strings.latestBrief }
    static var systemStatus: String { strings.systemStatus }
    static var awaitingApproval: String { strings.awaitingApproval }
    static var shortcuts: String { strings.shortcuts }
    static var briefItems: String { strings.briefItems }

    // Panel states (health contract §7)
    static var stateFresh: String { strings.stateFresh }
    static var stateStale: String { strings.stateStale }
    static var stateBriefError: String { strings.stateBriefError }
    static var stateNotReady: String { strings.stateNotReady }
    static var stateUpdateRequired: String { strings.stateUpdateRequired }
    static var stateAiError: String { strings.stateAiError }
    static var stateAiSetupRequired: String { strings.stateAiSetupRequired }
    static var stateCoreUnreachable: String { strings.stateCoreUnreachable }
    static var stateLoading: String { strings.stateLoading }

    static var staleDetail: String { strings.staleDetail }
    static var briefErrorDetail: String { strings.briefErrorDetail }
    static var notReadyDetail: String { strings.notReadyDetail }
    static var updateRequiredDetail: String { strings.updateRequiredDetail }
    static var aiErrorDetail: String { strings.aiErrorDetail }
    static var aiSetupRequiredDetail: String { strings.aiSetupRequiredDetail }
    static var coreUnreachableDetail: String { strings.coreUnreachableDetail }
    static var loadingDetail: String { strings.loadingDetail }
    static var noBriefYet: String { strings.noBriefYet }

    // Rows
    static var aiReady: String { strings.aiReady }
    static var aiPaused: String { strings.aiPaused }
    static var aiProviderPrefix: String { strings.aiProviderPrefix }
    static var aiLastSuccessPrefix: String { strings.aiLastSuccessPrefix }
    static var aiNoCompletionYet: String { strings.aiNoCompletionYet }
    static var setUpAI: String { strings.setUpAI }
    static var reconfigureAI: String { strings.reconfigureAI }
    static var online: String { strings.online }
    static var offline: String { strings.offline }
    static var degraded: String { strings.degraded }
    static var privacyModePrefix: String { strings.privacyModePrefix }
    static var generatedPrefix: String { strings.generatedPrefix }
    static var versionPrefix: String { strings.versionPrefix }
    static var duePrefix: String { strings.duePrefix }
    static var uncertaintyPrefix: String { strings.uncertaintyPrefix }
    static var confidencePrefix: String { strings.confidencePrefix }
    static var sources: String { strings.sources }
    static var reviewAction: String { strings.reviewAction }
    static var open: String { strings.open }
    static var noApprovals: String { strings.noApprovals }
    static var failingChecksPrefix: String { strings.failingChecksPrefix }

    // Actions (blueprint §1.1 — every item listed there)
    static var openFullBrief: String { strings.openFullBrief }
    static var pauseAI: String { strings.pauseAI }
    static var resumeAI: String { strings.resumeAI }
    static var openPWA: String { strings.openPWA }
    static var settings: String { strings.settings }
    static var quitUI: String { strings.quitUI }
    static var quitUIHint: String { strings.quitUIHint }
    static var openDiagnostics: String { strings.openDiagnostics }
    static var restartServices: String { strings.restartServices }
    static var pauseFailed: String { strings.pauseFailed }

    static var settingsPlaceholderTitle: String { strings.settingsPlaceholderTitle }
    static var settingsPlaceholderBody: String { strings.settingsPlaceholderBody }

    // Settings → Uninstall (S1-13; blueprint §1.1, §12.2)
    static var settingsUninstallSection: String { strings.settingsUninstallSection }
    static var settingsUninstallBody: String { strings.settingsUninstallBody }
    static var uninstall: String { strings.uninstall }
    static var uninstallTitle: String { strings.uninstallTitle }
    static var uninstallIntro: String { strings.uninstallIntro }
    static var uninstallKeepData: String { strings.uninstallKeepData }
    static var uninstallKeepDataDetail: String { strings.uninstallKeepDataDetail }
    static var uninstallDeleteEverything: String { strings.uninstallDeleteEverything }
    static var uninstallDeleteEverythingDetail: String { strings.uninstallDeleteEverythingDetail }
    static var uninstallConfirm: String { strings.uninstallConfirm }
    static var cancel: String { strings.cancel }
    static var uninstallRunning: String { strings.uninstallRunning }
    static var uninstallRunningDetail: String { strings.uninstallRunningDetail }
    static var uninstallDoneTitle: String { strings.uninstallDoneTitle }
    static var uninstallDoneKeptData: String { strings.uninstallDoneKeptData }
    static var uninstallDoneDeleted: String { strings.uninstallDoneDeleted }
    static var uninstallSecretsRemovedPrefix: String { strings.uninstallSecretsRemovedPrefix }
    static var uninstallSecretsSkipped: String { strings.uninstallSecretsSkipped }
    static var uninstallTrashed: String { strings.uninstallTrashed }
    static var uninstallNotTrashed: String { strings.uninstallNotTrashed }
    static var uninstallFailedTitle: String { strings.uninstallFailedTitle }
    static var uninstallFailedDetail: String { strings.uninstallFailedDetail }
    static var uninstallRetry: String { strings.uninstallRetry }
    static var quit: String { strings.quit }

    static func approvalsTitle(count: Int) -> String {
        strings.approvalsTitle(count: count)
    }

    static func badgeAccessibilityLabel(count: Int) -> String {
        strings.badgeAccessibilityLabel(count: count)
    }

    static func kindLabel(_ kind: String) -> String {
        strings.kinds[kind] ?? kind.capitalized
    }

    static func priorityLabel(_ priority: String) -> String {
        strings.priorities[priority] ?? priority.capitalized
    }

    static func sourceTypeLabel(_ type: String) -> String {
        strings.sourceTypes[type] ?? type.capitalized
    }

    static func approvalKindLabel(_ kind: String) -> String {
        strings.approvalKinds[kind] ?? kind.replacingOccurrences(of: "_", with: " ").capitalized
    }

    /// Every static string of both languages, for the secrets grep in MenuBarCopyTests.
    static var allStrings: [String] {
        [MenuBarStrings.en, MenuBarStrings.sv].flatMap(\.all)
    }
}

/// One language's table. Field order follows the panel top to bottom.
struct MenuBarStrings: Sendable {
    let language: MenuBarLanguage

    let appName: String
    let latestBrief: String
    let systemStatus: String
    let awaitingApproval: String
    let shortcuts: String
    let briefItems: String

    let stateFresh: String
    let stateStale: String
    let stateBriefError: String
    let stateNotReady: String
    let stateUpdateRequired: String
    let stateAiError: String
    let stateAiSetupRequired: String
    let stateCoreUnreachable: String
    let stateLoading: String

    let staleDetail: String
    let briefErrorDetail: String
    let notReadyDetail: String
    let updateRequiredDetail: String
    let aiErrorDetail: String
    let aiSetupRequiredDetail: String
    let coreUnreachableDetail: String
    let loadingDetail: String
    let noBriefYet: String

    let aiReady: String
    let aiPaused: String
    let aiProviderPrefix: String
    let aiLastSuccessPrefix: String
    let aiNoCompletionYet: String
    let setUpAI: String
    let reconfigureAI: String
    let online: String
    let offline: String
    let degraded: String
    let privacyModePrefix: String
    let generatedPrefix: String
    let versionPrefix: String
    let duePrefix: String
    let uncertaintyPrefix: String
    let confidencePrefix: String
    let sources: String
    let reviewAction: String
    let open: String
    let noApprovals: String
    let failingChecksPrefix: String

    let openFullBrief: String
    let pauseAI: String
    let resumeAI: String
    let openPWA: String
    let settings: String
    let quitUI: String
    let quitUIHint: String
    let openDiagnostics: String
    let restartServices: String
    let pauseFailed: String

    let settingsPlaceholderTitle: String
    let settingsPlaceholderBody: String

    let settingsUninstallSection: String
    let settingsUninstallBody: String
    let uninstall: String
    let uninstallTitle: String
    let uninstallIntro: String
    let uninstallKeepData: String
    let uninstallKeepDataDetail: String
    let uninstallDeleteEverything: String
    let uninstallDeleteEverythingDetail: String
    let uninstallConfirm: String
    let cancel: String
    let uninstallRunning: String
    let uninstallRunningDetail: String
    let uninstallDoneTitle: String
    let uninstallDoneKeptData: String
    let uninstallDoneDeleted: String
    let uninstallSecretsRemovedPrefix: String
    let uninstallSecretsSkipped: String
    let uninstallTrashed: String
    let uninstallNotTrashed: String
    let uninstallFailedTitle: String
    let uninstallFailedDetail: String
    let uninstallRetry: String
    let quit: String

    let taskAwaitingApproval: String
    let tasksAwaitingApproval: String

    let kinds: [String: String]
    let priorities: [String: String]
    let sourceTypes: [String: String]
    let approvalKinds: [String: String]

    func approvalsTitle(count: Int) -> String {
        count == 0 ? awaitingApproval : "\(awaitingApproval) (\(count))"
    }

    func badgeAccessibilityLabel(count: Int) -> String {
        count == 1 ? "1 \(taskAwaitingApproval)" : "\(count) \(tasksAwaitingApproval)"
    }

    /// Every string in the table, including the lookup tables' values.
    var all: [String] {
        let fields = Mirror(reflecting: self).children.compactMap { $0.value as? String }
        let tables = [kinds, priorities, sourceTypes, approvalKinds].flatMap { $0.values }

        return fields + tables + [approvalsTitle(count: 2), badgeAccessibilityLabel(count: 1), badgeAccessibilityLabel(count: 2)]
    }

    static let en = MenuBarStrings(
        language: .en,
        appName: "Laravel Executive AI",
        latestBrief: "Latest brief",
        systemStatus: "System status",
        awaitingApproval: "Awaiting your approval",
        shortcuts: "Shortcuts",
        briefItems: "In this brief",
        stateFresh: "Up to date",
        stateStale: "Stale brief",
        stateBriefError: "Brief generation failed",
        stateNotReady: "Core not ready",
        stateUpdateRequired: "Update required",
        stateAiError: "AI error",
        stateAiSetupRequired: "AI setup required",
        stateCoreUnreachable: "Core unreachable",
        stateLoading: "Waiting for the core",
        staleDetail: "The scheduled brief has not been generated yet. The last brief is shown.",
        briefErrorDetail: "The last scheduled brief generation failed. The last good brief is shown.",
        notReadyDetail: "One or more services are not healthy.",
        updateRequiredDetail: "This brief uses a newer format than this version of the app can show.",
        aiErrorDetail: "The last AI request failed. Reconfigure AI in Settings.",
        aiSetupRequiredDetail: "Add a model or an API key to start generating briefs. CRM, previous briefs and admin keep working.",
        coreUnreachableDetail: "The local core is not answering. The last cached brief is shown.",
        loadingDetail: "No brief has been cached on this Mac yet.",
        noBriefYet: "No brief yet",
        aiReady: "AI ready",
        aiPaused: "AI actions paused",
        aiProviderPrefix: "Provider",
        aiLastSuccessPrefix: "Last successful completion",
        aiNoCompletionYet: "No completion has succeeded yet",
        setUpAI: "Set up AI",
        reconfigureAI: "Reconfigure AI",
        online: "Online",
        offline: "Offline — local features work, sync is queued",
        degraded: "Some sources unreachable",
        privacyModePrefix: "Privacy mode",
        generatedPrefix: "Generated",
        versionPrefix: "v",
        duePrefix: "Due",
        uncertaintyPrefix: "Uncertain",
        confidencePrefix: "confidence",
        sources: "Sources",
        reviewAction: "Review action",
        open: "Open",
        noApprovals: "Nothing waiting for you.",
        failingChecksPrefix: "Failing",
        openFullBrief: "Open full brief",
        pauseAI: "Pause AI actions",
        resumeAI: "Resume AI actions",
        openPWA: "Open PWA",
        settings: "Settings…",
        quitUI: "Quit UI",
        quitUIHint: "Quits this menu bar app only. The local services keep running.",
        openDiagnostics: "Open diagnostics",
        restartServices: "Restart services",
        pauseFailed: "Could not change the AI pause state.",
        settingsPlaceholderTitle: "Settings",
        settingsPlaceholderBody: "Services, Local Models, Privacy, Updates, Backups, Diagnostics and Power arrive in later sprints.",
        settingsUninstallSection: "Uninstall",
        settingsUninstallBody: "Removes the local services, the runtime, the Keychain items and this app from this Mac. You choose whether your data stays.",
        uninstall: "Uninstall…",
        uninstallTitle: "Uninstall Laravel Executive AI",
        uninstallIntro: "The local services are stopped, the launch agent is unregistered, every Keychain item this app created is deleted, the runtime is removed and the app is moved to the Trash.",
        uninstallKeepData: "Keep my data",
        uninstallKeepDataDetail: "Your database, briefs and files stay in Application Support. Installing the app again continues with the same owner account and the same briefs.",
        uninstallDeleteEverything: "Delete everything",
        uninstallDeleteEverythingDetail: "Database, briefs, files, logs and caches are deleted. This cannot be undone.",
        uninstallConfirm: "Uninstall",
        cancel: "Cancel",
        uninstallRunning: "Uninstalling…",
        uninstallRunningDetail: "Stopping services, removing Keychain items and files.",
        uninstallDoneTitle: "Uninstalled",
        uninstallDoneKeptData: "Your data was kept in Application Support. Install the app again to continue with the same owner account and briefs.",
        uninstallDoneDeleted: "Your data, logs and caches were deleted.",
        uninstallSecretsRemovedPrefix: "Keychain items removed",
        uninstallSecretsSkipped: "The Keychain could not be swept because the Supervisor was not running. Search Keychain Access for “Laravel Executive AI” and delete any remaining items.",
        uninstallTrashed: "The app has been moved to the Trash. Quit to finish.",
        uninstallNotTrashed: "Quit, then drag the app to the Trash to finish.",
        uninstallFailedTitle: "Uninstall did not finish",
        uninstallFailedDetail: "Nothing is done twice. Try again to continue where it stopped.",
        uninstallRetry: "Try again",
        quit: "Quit",
        taskAwaitingApproval: "task awaiting approval",
        tasksAwaitingApproval: "tasks awaiting approval",
        kinds: ["action": "Action", "followup": "Follow-up", "risk": "Risk", "info": "Info", "commitment": "Commitment"],
        priorities: ["critical": "Critical", "high": "High", "normal": "Normal", "low": "Low"],
        sourceTypes: ["email": "Email", "crm": "CRM", "github": "GitHub", "calendar": "Calendar", "manual": "Manual", "ai_inference": "AI inference"],
        approvalKinds: [
            "email_send": "Send email", "crm_delete": "Delete in CRM", "github_write": "Write to GitHub",
            "external_form": "Submit form", "automation_action": "Automation", "mcp_tool_call": "MCP tool call",
        ]
    )

    static let sv = MenuBarStrings(
        language: .sv,
        appName: "Laravel Executive AI",
        latestBrief: "Senaste brief",
        systemStatus: "Systemstatus",
        awaitingApproval: "Väntar på ditt godkännande",
        shortcuts: "Genvägar",
        briefItems: "I den här briefen",
        stateFresh: "Uppdaterad",
        stateStale: "Inaktuell brief",
        stateBriefError: "Briefgenereringen misslyckades",
        stateNotReady: "Kärnan är inte klar",
        stateUpdateRequired: "Uppdatering krävs",
        stateAiError: "AI-fel",
        stateAiSetupRequired: "AI-konfiguration krävs",
        stateCoreUnreachable: "Kärnan kan inte nås",
        stateLoading: "Väntar på kärnan",
        staleDetail: "Den schemalagda briefen har inte genererats ännu. Den senaste briefen visas.",
        briefErrorDetail: "Den senaste schemalagda briefgenereringen misslyckades. Den senaste fungerande briefen visas.",
        notReadyDetail: "En eller flera tjänster är inte friska.",
        updateRequiredDetail: "Den här briefen använder ett nyare format än den här versionen av appen kan visa.",
        aiErrorDetail: "Den senaste AI-förfrågan misslyckades. Konfigurera om AI i Inställningar.",
        aiSetupRequiredDetail: "Lägg till en modell eller en API-nyckel för att börja generera briefer. CRM, tidigare briefer och admin fortsätter att fungera.",
        coreUnreachableDetail: "Den lokala kärnan svarar inte. Den senast cachade briefen visas.",
        loadingDetail: "Ingen brief har cachats på den här Macen ännu.",
        noBriefYet: "Ingen brief ännu",
        aiReady: "AI redo",
        aiPaused: "AI-åtgärder pausade",
        aiProviderPrefix: "Leverantör",
        aiLastSuccessPrefix: "Senaste lyckade förfrågan",
        aiNoCompletionYet: "Ingen förfrågan har lyckats ännu",
        setUpAI: "Konfigurera AI",
        reconfigureAI: "Konfigurera om AI",
        online: "Online",
        offline: "Offline — lokala funktioner fungerar, synk är köad",
        degraded: "Vissa källor kan inte nås",
        privacyModePrefix: "Sekretessläge",
        generatedPrefix: "Genererad",
        versionPrefix: "v",
        duePrefix: "Deadline",
        uncertaintyPrefix: "Osäkert",
        confidencePrefix: "konfidens",
        sources: "Källor",
        reviewAction: "Granska åtgärd",
        open: "Öppna",
        noApprovals: "Inget väntar på dig.",
        failingChecksPrefix: "Fallerar",
        openFullBrief: "Öppna hela briefen",
        pauseAI: "Pausa AI-åtgärder",
        resumeAI: "Återuppta AI-åtgärder",
        openPWA: "Öppna PWA",
        settings: "Inställningar…",
        quitUI: "Avsluta gränssnittet",
        quitUIHint: "Avslutar bara den här menyradsappen. De lokala tjänsterna fortsätter att köra.",
        openDiagnostics: "Öppna diagnostik",
        restartServices: "Starta om tjänster",
        pauseFailed: "Kunde inte ändra AI:ns pausläge.",
        settingsPlaceholderTitle: "Inställningar",
        settingsPlaceholderBody: "Tjänster, Lokala modeller, Sekretess, Uppdateringar, Säkerhetskopior, Diagnostik och Energi kommer i senare sprintar.",
        settingsUninstallSection: "Avinstallera",
        settingsUninstallBody: "Tar bort de lokala tjänsterna, körmiljön, nyckelringsposterna och den här appen från den här Macen. Du väljer om dina data ska behållas.",
        uninstall: "Avinstallera…",
        uninstallTitle: "Avinstallera Laravel Executive AI",
        uninstallIntro: "De lokala tjänsterna stoppas, startagenten avregistreras, varje nyckelringspost som appen skapat raderas, körmiljön tas bort och appen flyttas till papperskorgen.",
        uninstallKeepData: "Behåll mina data",
        uninstallKeepDataDetail: "Din databas, dina briefer och filer ligger kvar i Application Support. Installerar du appen igen fortsätter den med samma ägarkonto och samma briefer.",
        uninstallDeleteEverything: "Ta bort allt",
        uninstallDeleteEverythingDetail: "Databas, briefer, filer, loggar och cachar raderas. Det går inte att ångra.",
        uninstallConfirm: "Avinstallera",
        cancel: "Avbryt",
        uninstallRunning: "Avinstallerar…",
        uninstallRunningDetail: "Stoppar tjänster, tar bort nyckelringsposter och filer.",
        uninstallDoneTitle: "Avinstallerad",
        uninstallDoneKeptData: "Dina data behölls i Application Support. Installera appen igen för att fortsätta med samma ägarkonto och briefer.",
        uninstallDoneDeleted: "Dina data, loggar och cachar raderades.",
        uninstallSecretsRemovedPrefix: "Nyckelringsposter borttagna",
        uninstallSecretsSkipped: "Nyckelringen kunde inte rensas eftersom Supervisorn inte kördes. Sök efter ”Laravel Executive AI” i Nyckelhanterare och radera eventuella kvarvarande poster.",
        uninstallTrashed: "Appen har flyttats till papperskorgen. Avsluta för att slutföra.",
        uninstallNotTrashed: "Avsluta och dra sedan appen till papperskorgen för att slutföra.",
        uninstallFailedTitle: "Avinstallationen slutfördes inte",
        uninstallFailedDetail: "Inget görs två gånger. Försök igen för att fortsätta där det stannade.",
        uninstallRetry: "Försök igen",
        quit: "Avsluta",
        taskAwaitingApproval: "uppgift väntar på godkännande",
        tasksAwaitingApproval: "uppgifter väntar på godkännande",
        kinds: ["action": "Åtgärd", "followup": "Uppföljning", "risk": "Risk", "info": "Info", "commitment": "Åtagande"],
        priorities: ["critical": "Kritisk", "high": "Hög", "normal": "Normal", "low": "Låg"],
        sourceTypes: ["email": "E-post", "crm": "CRM", "github": "GitHub", "calendar": "Kalender", "manual": "Manuell", "ai_inference": "AI-slutsats"],
        approvalKinds: [
            "email_send": "Skicka e-post", "crm_delete": "Radera i CRM", "github_write": "Skriv till GitHub",
            "external_form": "Skicka formulär", "automation_action": "Automatisering", "mcp_tool_call": "MCP-verktygsanrop",
        ]
    )
}
