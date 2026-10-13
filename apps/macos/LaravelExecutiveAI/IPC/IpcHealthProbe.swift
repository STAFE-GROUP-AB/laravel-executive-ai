// The installer's `HealthProbe` over the live IPC client (S1-08 part 2; installer.md §6).
//
// `HEALTH_CHECK` → `READY` is gated on `GET /health/ready` answering `ready: true`. That route
// sits behind `IpcAuthenticate:menubar,supervisor` (ipc-server.md), so the probe signs with the
// GUI's own `menubar` grant — the same session the panel uses — and reads the un-enveloped health
// document the route returns on `200` and `503` alike. The port is read when the probe runs, not
// when it is built: preflight records it in the checkpoint during the very install this probe
// belongs to. `/health/live` is the plain, unsigned liveness check of preflight 6.8.
//
// **The probe also sends the heartbeat (#124).** `ready` requires `menubar_integration`, which is
// an authenticated `menubar` heartbeat no older than 120 s (health-contract §2), and the only
// sender used to be `PanelModel.refresh()` — which does not run in the first-run installer
// process. So on a real first install that check could never pass and `HEALTH_CHECK` could only
// ever time out. The fix is not to weaken the gate: "menu integration" means *the GUI app is
// talking to the core*, and the installer **is** the GUI app, holding the one `menubar` session
// of this process (`IpcClientFactory.liveSession`). It says so, from the state that needs it to
// be true. At most one heartbeat per `heartbeatInterval` (20 s), well inside the §9 budget of
// 6/min and well inside the 120 s threshold.

import Foundation
import Synchronization

struct IpcHealthProbe: HealthProbe {
    /// §9 allows 6 heartbeats a minute and the check's threshold is 120 s, so one every 20 s is
    /// both well inside the budget and three times more often than the check needs.
    static let heartbeatInterval: TimeInterval = 20

    /// The loopback port preflight recorded (`preflight.port` in the checkpoint), or nil before.
    let port: @Sendable () -> Int?
    let session: IpcSession
    let makeClient: @Sendable (_ port: Int, _ session: IpcSession) -> LoopbackIpcClient
    private let now: @Sendable () -> Date
    /// A reference box: the probe is a value type handed around as `any HealthProbe`, and the
    /// "when did we last say hello" instant has to survive being copied.
    private let heartbeats = HeartbeatTicker()

    init(
        port: @escaping @Sendable () -> Int?,
        session: IpcSession,
        now: @escaping @Sendable () -> Date = { Date() },
        makeClient: @escaping @Sendable (Int, IpcSession) -> LoopbackIpcClient
    ) {
        self.port = port
        self.session = session
        self.now = now
        self.makeClient = makeClient
    }

    /// Reads the port from `store`'s checkpoint on every call.
    init(
        checkpointStore: any InstallCheckpointStore,
        session: IpcSession,
        now: @escaping @Sendable () -> Date = { Date() },
        makeClient: @escaping @Sendable (Int, IpcSession) -> LoopbackIpcClient
    ) {
        self.init(port: { (try? checkpointStore.load())?.preflight?.port }, session: session, now: now, makeClient: makeClient)
    }

    func readiness() async throws -> HealthReadiness {
        guard let port = port() else {
            throw HealthProbeError.unreachable("no loopback port has been recorded")
        }

        let client = makeClient(port, session)

        await sendHeartbeatIfDue(client)

        let response: IpcResponse

        do {
            response = try await client.send(.healthReady)
        } catch let error as IpcClientError {
            throw HealthProbeError.unreachable(error.userFacingReason)
        }

        guard let document = try? HealthDocument.decode(response.body) else {
            throw HealthProbeError.invalidResponse
        }

        return document.readiness
    }

    func live() async -> Bool {
        guard let port = port() else {
            return false
        }

        return await makeClient(port, session).live()
    }

    /// Feeds `menubar_integration` while the installer is the only part of the GUI that is
    /// running. Best-effort on purpose: a heartbeat that fails must not turn a readiness poll
    /// into a probe error — the next poll sends another one, and the readiness answer itself
    /// reports the check as failing if they never arrive.
    private func sendHeartbeatIfDue(_ client: LoopbackIpcClient) async {
        guard heartbeats.claim(at: now(), interval: Self.heartbeatInterval) else {
            return
        }

        _ = try? await client.heartbeat(panelOpen: false)
    }
}

/// Rate-limits the installer's heartbeat to one per interval (ipc-contract §9: 6/min).
final class HeartbeatTicker: Sendable {
    private let last = Mutex<Date?>(nil)

    /// True when a heartbeat is due now, and records that it was taken.
    func claim(at moment: Date, interval: TimeInterval) -> Bool {
        last.withLock { stored in
            if let previous = stored, moment.timeIntervalSince(previous) < interval {
                return false
            }

            stored = moment

            return true
        }
    }
}
