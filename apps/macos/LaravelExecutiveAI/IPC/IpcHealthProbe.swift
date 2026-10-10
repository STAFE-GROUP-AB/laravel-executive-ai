// The installer's `HealthProbe` over the live IPC client (S1-08 part 2; installer.md §6).
//
// `HEALTH_CHECK` → `READY` is gated on `GET /health/ready` answering `ready: true`. That route
// sits behind `IpcAuthenticate:menubar,supervisor` (ipc-server.md), so the probe signs with the
// GUI's own `menubar` grant — the same session the panel uses — and reads the un-enveloped health
// document the route returns on `200` and `503` alike. The port is read when the probe runs, not
// when it is built: preflight records it in the checkpoint during the very install this probe
// belongs to. `/health/live` is the plain, unsigned liveness check of preflight 6.8.

import Foundation

struct IpcHealthProbe: HealthProbe {
    /// The loopback port preflight recorded (`preflight.port` in the checkpoint), or nil before.
    let port: @Sendable () -> Int?
    let session: IpcSession
    let makeClient: @Sendable (_ port: Int, _ session: IpcSession) -> LoopbackIpcClient

    init(port: @escaping @Sendable () -> Int?, session: IpcSession, makeClient: @escaping @Sendable (Int, IpcSession) -> LoopbackIpcClient) {
        self.port = port
        self.session = session
        self.makeClient = makeClient
    }

    /// Reads the port from `store`'s checkpoint on every call.
    init(checkpointStore: any InstallCheckpointStore, session: IpcSession, makeClient: @escaping @Sendable (Int, IpcSession) -> LoopbackIpcClient) {
        self.init(port: { (try? checkpointStore.load())?.preflight?.port }, session: session, makeClient: makeClient)
    }

    func readiness() async throws -> HealthReadiness {
        guard let port = port() else {
            throw HealthProbeError.unreachable("no loopback port has been recorded")
        }

        let client = makeClient(port, session)
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
}
