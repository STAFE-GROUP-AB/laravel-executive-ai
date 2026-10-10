// Every user-visible string of the menu bar panel, in one place (S1-09, blueprint §1.1).
//
// English first; Swedish follows with S1-11's localisation pass. Nothing here interpolates a
// token, a pairing grant, a health `detail` or raw mail/CRM text: the only dynamic parts are
// counts, dates, check names and the brief's own content, which is the product (§11; threat
// model T-33/T-34). MenuBarCopyTests greps every string for `lxai1.`, `Bearer` and `signing_key`.

import Foundation

enum MenuBarCopy {
    static let appName = "Laravel Executive AI"

    // Section labels
    static let latestBrief = "Latest brief"
    static let systemStatus = "System status"
    static let awaitingApproval = "Awaiting your approval"
    static let shortcuts = "Shortcuts"
    static let briefItems = "In this brief"

    // Panel states (health contract §7)
    static let stateFresh = "Up to date"
    static let stateStale = "Stale brief"
    static let stateBriefError = "Brief generation failed"
    static let stateNotReady = "Core not ready"
    static let stateUpdateRequired = "Update required"
    static let stateAiError = "AI error"
    static let stateAiSetupRequired = "AI setup required"
    static let stateCoreUnreachable = "Core unreachable"
    static let stateLoading = "Waiting for the core"

    static let staleDetail = "The scheduled brief has not been generated yet. The last brief is shown."
    static let briefErrorDetail = "The last scheduled brief generation failed. The last good brief is shown."
    static let notReadyDetail = "One or more services are not healthy."
    static let updateRequiredDetail = "This brief uses a newer format than this version of the app can show."
    static let aiErrorDetail = "The last AI request failed. Reconfigure AI in Settings."
    static let aiSetupRequiredDetail = "Add a model or an API key to start generating briefs. CRM, previous briefs and admin keep working."
    static let coreUnreachableDetail = "The local core is not answering. The last cached brief is shown."
    static let loadingDetail = "No brief has been cached on this Mac yet."
    static let noBriefYet = "No brief yet"

    // Rows
    static let aiReady = "AI ready"
    static let aiPaused = "AI actions paused"
    static let online = "Online"
    static let offline = "Offline — local features work, sync is queued"
    static let degraded = "Some sources unreachable"
    static let privacyModePrefix = "Privacy mode"
    static let generatedPrefix = "Generated"
    static let versionPrefix = "v"
    static let duePrefix = "Due"
    static let uncertaintyPrefix = "Uncertain"
    static let confidencePrefix = "confidence"
    static let sources = "Sources"
    static let reviewAction = "Review action"
    static let open = "Open"
    static let noApprovals = "Nothing waiting for you."
    static let failingChecksPrefix = "Failing"

    // Actions (blueprint §1.1 — every item listed there)
    static let openFullBrief = "Open full brief"
    static let pauseAI = "Pause AI actions"
    static let resumeAI = "Resume AI actions"
    static let openPWA = "Open PWA"
    static let settings = "Settings…"
    static let quitUI = "Quit UI"
    static let quitUIHint = "Quits this menu bar app only. The local services keep running."
    static let openDiagnostics = "Open diagnostics"
    static let restartServices = "Restart services"
    static let pauseFailed = "Could not change the AI pause state."

    static let settingsPlaceholderTitle = "Settings"
    static let settingsPlaceholderBody = "Services, Local Models, Privacy, Updates, Backups, Diagnostics, Power and Uninstall arrive in later sprints."

    static func approvalsTitle(count: Int) -> String {
        count == 0 ? awaitingApproval : "\(awaitingApproval) (\(count))"
    }

    static func badgeAccessibilityLabel(count: Int) -> String {
        count == 1 ? "1 task awaiting approval" : "\(count) tasks awaiting approval"
    }

    static func kindLabel(_ kind: String) -> String {
        switch kind {
        case "action": "Action"
        case "followup": "Follow-up"
        case "risk": "Risk"
        case "info": "Info"
        case "commitment": "Commitment"
        default: kind.capitalized
        }
    }

    static func priorityLabel(_ priority: String) -> String {
        switch priority {
        case "critical": "Critical"
        case "high": "High"
        case "normal": "Normal"
        case "low": "Low"
        default: priority.capitalized
        }
    }

    static func sourceTypeLabel(_ type: String) -> String {
        switch type {
        case "email": "Email"
        case "crm": "CRM"
        case "github": "GitHub"
        case "calendar": "Calendar"
        case "manual": "Manual"
        case "ai_inference": "AI inference"
        default: type.capitalized
        }
    }

    static func approvalKindLabel(_ kind: String) -> String {
        switch kind {
        case "email_send": "Send email"
        case "crm_delete": "Delete in CRM"
        case "github_write": "Write to GitHub"
        case "external_form": "Submit form"
        case "automation_action": "Automation"
        case "mcp_tool_call": "MCP tool call"
        default: kind.replacingOccurrences(of: "_", with: " ").capitalized
        }
    }

    /// Every static string, for the secrets grep in MenuBarCopyTests.
    static var allStrings: [String] {
        [
            appName, latestBrief, systemStatus, awaitingApproval, shortcuts, briefItems,
            stateFresh, stateStale, stateBriefError, stateNotReady, stateUpdateRequired, stateAiError,
            stateAiSetupRequired, stateCoreUnreachable, stateLoading,
            staleDetail, briefErrorDetail, notReadyDetail, updateRequiredDetail, aiErrorDetail,
            aiSetupRequiredDetail, coreUnreachableDetail, loadingDetail, noBriefYet,
            aiReady, aiPaused, online, offline, degraded, privacyModePrefix, generatedPrefix, versionPrefix,
            duePrefix, uncertaintyPrefix, confidencePrefix, sources, reviewAction, open, noApprovals,
            failingChecksPrefix,
            openFullBrief, pauseAI, resumeAI, openPWA, settings, quitUI, quitUIHint, openDiagnostics,
            restartServices, pauseFailed, settingsPlaceholderTitle, settingsPlaceholderBody,
            approvalsTitle(count: 2), badgeAccessibilityLabel(count: 1), badgeAccessibilityLabel(count: 2),
        ]
    }
}
