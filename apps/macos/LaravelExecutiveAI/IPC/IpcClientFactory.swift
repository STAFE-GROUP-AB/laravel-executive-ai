// Builds the live IPC pieces from the app bundle (S1-08 part 2): one `IpcSession` per process
// (shared by the panel's client, the installer's health probe and "Reset security pairing", so
// there is exactly one live `menubar` session as ipc-contract §2 requires), and the panel's
// `CoreClient` — the loopback client when the install checkpoint names a port, otherwise
// `UnreachableCoreClient`, so the panel keeps its cache-only behaviour (S1-09 §14) until the
// core exists. Nothing here touches the Keychain, launchd or the network at construction time.

import Foundation

enum IpcClientFactory {
    /// RFC 3339 (UTC) start of this GUI process — the heartbeat's `since` (§6.5).
    static let processStartedAt = Date()

    /// The one `menubar` session of this process.
    static let liveSession: IpcSession = makeSession()

    static func makeSession(bundle: Bundle = .main) -> IpcSession {
        IpcSession(
            store: KeychainSessionStore.default(bundleIdentifier: bundleIdentifier(of: bundle)),
            control: SupervisorControlClientFactory.live(bundle: bundle)
        )
    }

    static func makeCoreClient(bundle: Bundle = .main, checkpointStore: any InstallCheckpointStore, session: IpcSession) -> any CoreClient {
        guard let port = (try? checkpointStore.load())?.preflight?.port, (1...65535).contains(port) else {
            return UnreachableCoreClient(reason: "The core is not installed yet.")
        }

        return makeClient(port: port, session: session, bundle: bundle)
    }

    static func makeClient(port: Int, session: IpcSession, bundle: Bundle = .main) -> LoopbackIpcClient {
        LoopbackIpcClient(
            configuration: configuration(port: port, bundle: bundle),
            session: session,
            sink: OSLogIpcSink(subsystem: bundleIdentifier(of: bundle))
        )
    }

    static func configuration(port: Int, bundle: Bundle = .main) -> LoopbackIpcClient.Configuration {
        LoopbackIpcClient.Configuration(port: port, clientVersion: clientVersion(of: bundle), since: since())
    }

    static func makeHealthProbe(bundle: Bundle = .main, checkpointStore: any InstallCheckpointStore, session: IpcSession) -> IpcHealthProbe {
        IpcHealthProbe(checkpointStore: checkpointStore, session: session) { port, session in
            makeClient(port: port, session: session, bundle: bundle)
        }
    }

    /// CREATE_OWNER over the same one `menubar` session (ipc-contract §6.9).
    static func makeOwnerCreator(
        bundle: Bundle = .main,
        checkpointStore: any InstallCheckpointStore,
        session: IpcSession,
        timeZone: TimeZone = .current,
        language: PreflightLanguage = PreflightLanguage.preferred()
    ) -> IpcOwnerCreator {
        let identifier = timeZone.identifier
        let tag = language.rawValue

        return IpcOwnerCreator(
            checkpointStore: checkpointStore,
            session: session,
            timeZoneIdentifier: { identifier },
            languageTag: { tag }
        ) { port, session in
            makeClient(port: port, session: session, bundle: bundle)
        }
    }

    /// `"<CFBundleShortVersionString> (<CFBundleVersion>)"`, the heartbeat's `client_version`.
    static func clientVersion(of bundle: Bundle) -> String {
        let marketing = bundle.object(forInfoDictionaryKey: "CFBundleShortVersionString") as? String ?? "0.0.0"
        let build = bundle.object(forInfoDictionaryKey: "CFBundleVersion") as? String ?? "0"

        return "\(marketing) (\(build))"
    }

    static func since(now: Date = processStartedAt) -> String {
        let formatter = ISO8601DateFormatter()
        formatter.formatOptions = [.withInternetDateTime]

        return formatter.string(from: now)
    }

    private static func bundleIdentifier(of bundle: Bundle) -> String {
        bundle.bundleIdentifier ?? SupervisorIdentity.defaultBundlePrefix + ".app"
    }
}
