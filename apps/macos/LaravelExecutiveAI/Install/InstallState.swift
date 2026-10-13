// Installer states (S1-05, blueprint §12.1).
//
// The nine core states run in one fixed order. The optional path is a separate set of states
// that can only be entered from READY; nothing on it is ever part of core-ready (§0, §12.2).

import Foundation

enum InstallState: String, Codable, CaseIterable, Sendable, Hashable {
    // Core sequence, §12.1.
    case welcome = "WELCOME"
    case preflight = "PREFLIGHT"
    case packageVerify = "PACKAGE_VERIFY"
    case installCore = "INSTALL_CORE"
    case createOwner = "CREATE_OWNER"
    case selectAI = "SELECT_AI"
    case bootServices = "BOOT_SERVICES"
    case healthCheck = "HEALTH_CHECK"
    case ready = "READY"

    // Optional path, §12.1. Each is an async post-core step in the install-measurement sense.
    case connectEmail = "CONNECT_EMAIL"
    case connectGitHub = "CONNECT_GITHUB"
    case peekabooPermissions = "PEEKABOO_PERMISSIONS"
    case remoteAccess = "REMOTE_ACCESS"
    case scheduleBrief = "SCHEDULE_BRIEF"

    static let coreSequence: [InstallState] = [
        .welcome, .preflight, .packageVerify, .installCore, .createOwner,
        .selectAI, .bootServices, .healthCheck, .ready,
    ]

    /// What this state leaves behind that `install/checkpoint.json` cannot record.
    ///
    /// **A checkpoint restores which states are done. It does not restore the processes those
    /// states started.** That is the defect behind #124: `INSTALL_CORE` has a durable effect (the
    /// runtime on disk, correctly recorded as `runtime.activated`) and a volatile one (the
    /// Supervisor registered and its three children running), and resume read the first as
    /// evidence for the second. On the failing Mac the second launch jumped straight to
    /// `CREATE_OWNER` with no Supervisor and no `frankenphp` process anywhere.
    ///
    /// So every state that has a volatile postcondition declares it here, and
    /// `InstallStateMachine` re-establishes each one — idempotently — for every state a resume
    /// skips. A new state with a volatile effect that does not appear in this list is the same
    /// bug again; `InstallEntryConditionTests` fails when one is added without declaring it.
    var volatileEffects: [InstallVolatileEffect] {
        switch self {
        case .installCore:
            return [.supervisionRunning]
        case .selectAI:
            return [.pendingProviderKey]
        default:
            return []
        }
    }

    static let optionalPath: [InstallState] = [
        .connectEmail, .connectGitHub, .peekabooPermissions, .remoteAccess, .scheduleBrief,
    ]

    var isCore: Bool {
        Self.coreSequence.contains(self)
    }

    var isOptional: Bool {
        Self.optionalPath.contains(self)
    }

    /// Position in the core sequence, 0 for WELCOME through 8 for READY; nil for optional states.
    var coreIndex: Int? {
        Self.coreSequence.firstIndex(of: self)
    }

    /// The next core state, nil for READY and for optional states.
    var nextCore: InstallState? {
        guard let index = coreIndex, index + 1 < Self.coreSequence.count else {
            return nil
        }

        return Self.coreSequence[index + 1]
    }

    /// The next step on the optional path, nil for the last step and for core states.
    var nextOptional: InstallState? {
        guard let index = Self.optionalPath.firstIndex(of: self), index + 1 < Self.optionalPath.count else {
            return nil
        }

        return Self.optionalPath[index + 1]
    }

    /// The explicit transition table. Everything not listed here is refused.
    ///
    /// - core state → exactly its successor;
    /// - READY → any optional step (the person picks the order on the Done checklist);
    /// - optional step → the next optional step, or back to READY.
    var allowedTransitions: Set<InstallState> {
        if self == .ready {
            return Set(Self.optionalPath)
        }

        if isOptional {
            var targets: Set<InstallState> = [.ready]

            if let next = nextOptional {
                targets.insert(next)
            }

            return targets
        }

        guard let next = nextCore else {
            return []
        }

        return [next]
    }

    func canTransition(to target: InstallState) -> Bool {
        allowedTransitions.contains(target)
    }

    /// States in which the installer waits for the person (§12.3 screens 1, 4 and 5).
    var waitsForPerson: Bool {
        switch self {
        case .welcome, .createOwner, .selectAI:
            return true
        default:
            return false
        }
    }

    /// The async-step name used in the install-measurement event log for an optional state.
    var asyncStep: InstallAsyncStep? {
        InstallAsyncStep(rawValue: rawValue)
    }

    /// Screen title, English. Only preflight blockers are localised (support matrix §6).
    var title: String {
        switch self {
        case .welcome: return "Welcome"
        case .preflight: return "Checking this Mac"
        case .packageVerify: return "Verifying the package"
        case .installCore: return "Installing"
        case .createOwner: return "Create your account"
        case .selectAI: return "Choose AI"
        case .bootServices: return "Starting services"
        case .healthCheck: return "Checking health"
        case .ready: return "Ready"
        case .connectEmail: return "Connect e-mail"
        case .connectGitHub: return "Connect GitHub"
        case .peekabooPermissions: return "Automation permissions"
        case .remoteAccess: return "Remote access"
        case .scheduleBrief: return "Schedule the brief"
        }
    }
}

/// Async post-core step names, exactly the set `packaging/manifest/install-timer/README.md` knows.
enum InstallAsyncStep: String, CaseIterable, Sendable, Hashable, Codable {
    case connectEmail = "CONNECT_EMAIL"
    case connectGitHub = "CONNECT_GITHUB"
    case peekabooPermissions = "PEEKABOO_PERMISSIONS"
    case remoteAccess = "REMOTE_ACCESS"
    case scheduleBrief = "SCHEDULE_BRIEF"
    case modelDownload = "MODEL_DOWNLOAD"
    case oauthGoogle = "OAUTH_GOOGLE"
    case oauthMicrosoft = "OAUTH_MICROSOFT"
    case oauthGitHub = "OAUTH_GITHUB"
    case iCloudMail = "ICLOUD_MAIL"
    case hey = "HEY"
    case tailscaleLogin = "TAILSCALE_LOGIN"
    case iosPush = "IOS_PUSH"
    case emailVerification = "EMAIL_VERIFICATION"
}

enum InstallAsyncOutcome: String, Sendable, Hashable, Codable {
    case completed
    case skipped
    case failed
    case cancelled
}

/// A postcondition that lives in a process, a launchd registration or memory — never on disk.
///
/// The distinction exists because `install/checkpoint.json` can only record the durable half of
/// what a state did. See `InstallState.volatileEffects` for why that mattered (#124).
enum InstallVolatileEffect: String, CaseIterable, Sendable {
    /// The Supervisor LaunchAgent is registered and answering, so the keys exist, the database is
    /// migrated and the core is serving (ADR-001 D3 as amended, services.md §2). Re-established
    /// by the same idempotent `startSupervisor` call the fresh run makes.
    case supervisionRunning

    /// The own API key from SELECT_AI, held in memory for the one Keychain writer
    /// (`PendingSecretHandoff`, ADR-003 D2 — the installer never writes the Keychain). Being
    /// unpersisted is the point, so it cannot be restored: a resume that skips a SELECT_AI whose
    /// key was never handed over shows screen 5 again rather than finishing with no provider
    /// configured and no explanation.
    case pendingProviderKey

    var label: String {
        switch self {
        case .supervisionRunning: return "the Supervisor and the core running"
        case .pendingProviderKey: return "the AI provider key held for the Keychain writer"
        }
    }

    /// Whether re-running the producing work restores it, or the state has to be re-entered.
    var isRestorable: Bool {
        switch self {
        case .supervisionRunning: return true
        case .pendingProviderKey: return false
        }
    }
}

/// A transition the table refuses.
enum InstallTransitionError: Error, Equatable, Sendable {
    case notAllowed(from: InstallState, to: InstallState)
    case optionalBeforeReady(InstallState)
    case notWaitingForInput(InstallState)
    case alreadyReady
}
