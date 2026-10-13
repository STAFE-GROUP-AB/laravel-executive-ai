// CREATE_OWNER over the paired `menubar` session (ipc-contract §6.9, issues #121 and #124).
//
// Screen 4's draft goes to `POST /ipc/v1/owner` on the loopback client. **Correction to #121's
// premise:** pairing does *not* happen "one state earlier at BOOT_SERVICES" — BOOT_SERVICES is
// two states *after* this one (`InstallState.coreSequence`), and #121 shipped believing
// otherwise, which is why the first signed build failed here with "pairing failed: the
// Supervisor did not answer". What makes this reachable is that INSTALL_CORE now ends by
// registering the Supervisor LaunchAgent (#124), so by the time this runs the Supervisor is
// live, it has minted `K`, and the core is serving. Pairing is then a lazy XPC call inside
// `IpcSession` on the first request — still no new credential, prompt or authentication step
// here.
//
// The core may still be coming up while the person types, so this waits for `/health/live`
// before the first signed request rather than reporting a core that is merely slow as broken.
//
// The core answers one of three things and this type maps each onto the hand-off the installer
// understands:
//
//   200 { "created": true }   → OwnerHandoff(alreadyExisted: false)   the account exists now
//   409 owner_exists          → OwnerHandoff(alreadyExisted: true)    a data-preserving reinstall;
//                                                                     nothing was created, and the
//                                                                     install moves on rather than
//                                                                     showing a red box
//   anything else             → OwnerCreationError
//
// The split between the two error cases is "can the person do something about it here?":
// `.unavailable` is the core not answering — not installed, not reachable, not paired, no key,
// too old to know this operation — and `.rejected` is the core answering that this account cannot
// be created, which means the form is wrong. Nothing in the draft is logged (ADR-003 D7); the
// error strings are the client's fixed sentences, never a header, a token or the address typed in.

import Foundation

struct IpcOwnerCreator: OwnerCreating {
    /// The loopback port preflight recorded (`preflight.port` in the checkpoint), or nil before.
    let port: @Sendable () -> Int?
    let session: IpcSession
    /// The IANA zone the first brief is scheduled in: the Mac's.
    let timeZoneIdentifier: @Sendable () -> String
    /// The BCP-47 tag the briefs are written in: the installer's language.
    let languageTag: @Sendable () -> String
    let makeClient: @Sendable (_ port: Int, _ session: IpcSession) -> LoopbackIpcClient
    /// How long to let the core finish starting before calling it unreachable. INSTALL_CORE
    /// started the Supervisor moments ago; it still has to mint the keys, migrate and spawn.
    var waiting = Waiting()

    struct Waiting: Sendable {
        var attempts = 40
        var interval: Duration = .milliseconds(500)
        var sleep: @Sendable (Duration) async throws -> Void = { try await Task.sleep(for: $0) }

        init() {}
    }

    init(
        port: @escaping @Sendable () -> Int?,
        session: IpcSession,
        timeZoneIdentifier: @escaping @Sendable () -> String = { TimeZone.current.identifier },
        languageTag: @escaping @Sendable () -> String = { PreflightLanguage.preferred().rawValue },
        waiting: Waiting = Waiting(),
        makeClient: @escaping @Sendable (Int, IpcSession) -> LoopbackIpcClient
    ) {
        self.port = port
        self.session = session
        self.timeZoneIdentifier = timeZoneIdentifier
        self.languageTag = languageTag
        self.waiting = waiting
        self.makeClient = makeClient
    }

    /// Reads the port from `store`'s checkpoint on every call: preflight records it during the
    /// very install this creator belongs to.
    init(
        checkpointStore: any InstallCheckpointStore,
        session: IpcSession,
        timeZoneIdentifier: @escaping @Sendable () -> String = { TimeZone.current.identifier },
        languageTag: @escaping @Sendable () -> String = { PreflightLanguage.preferred().rawValue },
        waiting: Waiting = Waiting(),
        makeClient: @escaping @Sendable (Int, IpcSession) -> LoopbackIpcClient
    ) {
        self.init(
            port: { (try? checkpointStore.load())?.preflight?.port },
            session: session,
            timeZoneIdentifier: timeZoneIdentifier,
            languageTag: languageTag,
            waiting: waiting,
            makeClient: makeClient
        )
    }

    func createOwner(_ draft: OwnerDraft) async throws -> OwnerHandoff {
        guard draft.isValid else {
            throw OwnerCreationError.rejected("Check the name and the e-mail address.")
        }

        guard let port = port() else {
            throw OwnerCreationError.unavailable("The core has no loopback port yet; run the install again from the start.")
        }

        let client = makeClient(port, session)

        // The Supervisor was registered at the end of INSTALL_CORE; the core it spawns has to
        // read APP_KEY over the broker and run the migrations before it serves. `/health/live`
        // needs no token and no pairing, so it tells "not up yet" apart from "pairing failed".
        try await waitForCore(client)

        let operation = IpcOperation.createOwner(draft, timeZone: timeZoneIdentifier(), language: languageTag())

        do {
            let response = try await client.send(operation)

            guard (try? response.envelopeData()) != nil else {
                throw OwnerCreationError.unavailable("The core answered something that is not the IPC envelope.")
            }

            return OwnerHandoff(alreadyExisted: false)
        } catch IpcClientError.ownerExists {
            // §6.9: the refusal that is not a failure.
            return OwnerHandoff(alreadyExisted: true)
        } catch let error as IpcClientError {
            throw Self.map(error)
        }
    }

    /// Polls the unsigned `/health/live` until the core answers. A core that never answers is
    /// `.unavailable` with the reason the person can act on — not a pairing error, which is what
    /// the first signed build reported for exactly this situation (#124).
    private func waitForCore(_ client: LoopbackIpcClient) async throws {
        for attempt in 1...max(waiting.attempts, 1) {
            try Task.checkCancellation()

            if await client.live() {
                return
            }

            if attempt < waiting.attempts {
                try await waiting.sleep(waiting.interval)
            }
        }

        throw OwnerCreationError.unavailable("The core is not answering yet. The background service may need your permission in System Settings › Login Items.")
    }

    /// The §8.2 table, split into the two cases screen 4 can act on.
    static func map(_ error: IpcClientError) -> OwnerCreationError {
        switch error {
        case .ownerExists:
            // Handled above; mapped for totality.
            .rejected(error.userFacingReason)
        case .validationFailed(_, let details):
            .rejected(details.isEmpty ? "The core did not accept these details." : "The core did not accept these details: \(details.keys.sorted().joined(separator: ", ")).")
        case .policyDenied(let message):
            .rejected(message)
        case .notFound:
            .unavailable("This core does not offer owner creation over IPC; repair the installation.")
        case .versionUnsupported:
            .unavailable("The core speaks a newer version of the local protocol; update Laravel Executive AI.")
        case .forbiddenScope, .originRejected, .elevationRequired, .reauthRequired, .stateConflict, .badRequest, .unexpected:
            .rejected(error.userFacingReason)
        case .unreachable, .supervisorUnreachable, .sessionInvalid, .signatureInvalid, .timestampSkew,
             .nonceReplayed, .secretsUnavailable, .internalError, .rateLimited, .redirected:
            .unavailable(error.userFacingReason)
        }
    }
}
