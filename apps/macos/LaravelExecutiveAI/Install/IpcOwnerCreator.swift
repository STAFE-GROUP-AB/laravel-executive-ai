// CREATE_OWNER over the paired `menubar` session (ipc-contract §6.9, issue #121).
//
// Screen 4's draft goes to `POST /ipc/v1/owner` on the loopback client this process already
// holds — pairing happened at BOOT_SERVICES, one state earlier, so no new credential, prompt or
// authentication step belongs here. The core answers one of three things and this type maps each
// onto the hand-off the installer understands:
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

    init(
        port: @escaping @Sendable () -> Int?,
        session: IpcSession,
        timeZoneIdentifier: @escaping @Sendable () -> String = { TimeZone.current.identifier },
        languageTag: @escaping @Sendable () -> String = { PreflightLanguage.preferred().rawValue },
        makeClient: @escaping @Sendable (Int, IpcSession) -> LoopbackIpcClient
    ) {
        self.port = port
        self.session = session
        self.timeZoneIdentifier = timeZoneIdentifier
        self.languageTag = languageTag
        self.makeClient = makeClient
    }

    /// Reads the port from `store`'s checkpoint on every call: preflight records it during the
    /// very install this creator belongs to.
    init(
        checkpointStore: any InstallCheckpointStore,
        session: IpcSession,
        timeZoneIdentifier: @escaping @Sendable () -> String = { TimeZone.current.identifier },
        languageTag: @escaping @Sendable () -> String = { PreflightLanguage.preferred().rawValue },
        makeClient: @escaping @Sendable (Int, IpcSession) -> LoopbackIpcClient
    ) {
        self.init(
            port: { (try? checkpointStore.load())?.preflight?.port },
            session: session,
            timeZoneIdentifier: timeZoneIdentifier,
            languageTag: languageTag,
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

        let operation = IpcOperation.createOwner(draft, timeZone: timeZoneIdentifier(), language: languageTag())

        do {
            let response = try await makeClient(port, session).send(operation)

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
