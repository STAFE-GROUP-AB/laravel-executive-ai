// The GUI's end of ipc-contract §7 (S1-07): `SupervisorControlling` over `NSXPCConnection`.
//
// Thin by design: one connection to `<prefix>.supervisor.control`, the A12 code-signing
// requirement on it, a 10 s budget per call (ipc-contract §7), and the JSON payloads of
// `SupervisorControlXPC.swift`. S1-08 part 2 calls `pair`/`elevate` from here; S1-09's panel
// reaches `restart`/`stopAll` through `SupervisorServiceControl`; the installer's
// `LaunchAgentServiceBooter` uses `services()` and `storeProviderApiKey`.

import Foundation

final class XPCSupervisorControlClient: SupervisorControlling, @unchecked Sendable {
    static let timeout: Duration = .seconds(10)

    private let machServiceName: String
    private let requirement: String?
    private let allowUnsignedPeer: Bool
    private let lock = NSLock()
    private var connection: NSXPCConnection?

    init(machServiceName: String, requirement: String?, allowUnsignedPeer: Bool = false) {
        self.machServiceName = machServiceName
        self.requirement = requirement
        self.allowUnsignedPeer = allowUnsignedPeer
    }

    func pair(client: IpcClient) async throws -> PairingGrant {
        try await call { proxy, finish in
            proxy.pair(client: client.rawValue) { data, error in finish(Self.result(PairingGrant.self, data, error)) }
        }
    }

    func resetPairing() async throws -> PairingGrant {
        try await call { proxy, finish in
            proxy.resetPairing { data, error in finish(Self.result(PairingGrant.self, data, error)) }
        }
    }

    func services() async throws -> [ServiceStatus] {
        try await call { proxy, finish in
            proxy.services { data, error in finish(Self.result([ServiceStatus].self, data, error)) }
        }
    }

    func restart(service: ServiceName) async throws {
        try await call { proxy, finish in
            proxy.restart(service: service.rawValue) { error in finish(Self.result(error)) }
        }
    }

    func stopAll() async throws {
        try await call { proxy, finish in
            proxy.stopAll { error in finish(Self.result(error)) }
        }
    }

    func startAll() async throws {
        try await call { proxy, finish in
            proxy.startAll { error in finish(Self.result(error)) }
        }
    }

    func elevate(sessionId: String, operation: String, nonce: String) async throws -> Elevation {
        try await call { proxy, finish in
            proxy.elevate(sessionId: sessionId, operation: operation, nonce: nonce) { data, error in finish(Self.result(Elevation.self, data, error)) }
        }
    }

    func storeProviderApiKey(provider: String, apiKey: TransientSecret) async throws {
        try await call { proxy, finish in
            apiKey.use { value in
                proxy.storeProviderApiKey(provider: provider, apiKey: value) { error in finish(Self.result(error)) }
            }
        }
    }

    // MARK: - Plumbing

    private func call<T: Sendable>(_ body: @escaping @Sendable (SupervisorControlXPCProtocol, @escaping @Sendable (Result<T, SupervisorControlError>) -> Void) -> Void) async throws -> T {
        let connection = try currentConnection()

        return try await withCheckedThrowingContinuation { (continuation: CheckedContinuation<T, any Error>) in
            let once = ResumeOnce(continuation)

            // ipc-contract §7: 10 s per call. Whichever of reply, error handler or timeout
            // comes first resumes; the others are no-ops.
            let timeout = Task {
                try await Task.sleep(for: Self.timeout)
                once.resume(.failure(SupervisorControlError.unreachable("timeout")))
            }

            let proxy = connection.remoteObjectProxyWithErrorHandler { error in
                timeout.cancel()
                once.resume(.failure(SupervisorControlError.unreachable("xpc \((error as NSError).code)")))
            }

            guard let typed = proxy as? SupervisorControlXPCProtocol else {
                timeout.cancel()
                once.resume(.failure(SupervisorControlError.unreachable("proxy")))
                return
            }

            body(typed) { result in
                timeout.cancel()
                once.resume(result.mapError { $0 as any Error })
            }
        }
    }

    private func currentConnection() throws -> NSXPCConnection {
        try lock.withLock {
            if let connection {
                return connection
            }

            guard requirement != nil || allowUnsignedPeer else {
                throw SupervisorControlError.unreachable("no signing requirement is available for the control channel")
            }

            let connection = NSXPCConnection(machServiceName: machServiceName)

            if let requirement {
                connection.setCodeSigningRequirement(requirement)
            }

            connection.remoteObjectInterface = NSXPCInterface(with: SupervisorControlXPCProtocol.self)
            connection.invalidationHandler = { [weak self] in
                self?.lock.withLock { self?.connection = nil }
            }
            connection.resume()
            self.connection = connection

            return connection
        }
    }

    private static func result<T: Decodable>(_ type: T.Type, _ data: Data?, _ error: String?) -> Result<T, SupervisorControlError> {
        if let error {
            return .failure(SupervisorControlWire.error(from: error))
        }

        guard let value = SupervisorControlWire.decode(type, from: data) else {
            return .failure(.unreachable("undecodable reply"))
        }

        return .success(value)
    }

    private static func result(_ error: String?) -> Result<Void, SupervisorControlError> {
        if let error {
            return .failure(SupervisorControlWire.error(from: error))
        }

        return .success(())
    }
}

/// A continuation that tolerates both the reply and the error handler firing.
private final class ResumeOnce<T: Sendable>: @unchecked Sendable {
    private let lock = NSLock()
    private var continuation: CheckedContinuation<T, any Error>?

    init(_ continuation: CheckedContinuation<T, any Error>) {
        self.continuation = continuation
    }

    func resume(_ result: Result<T, any Error>) {
        let continuation = lock.withLock { () -> CheckedContinuation<T, any Error>? in
            let current = self.continuation
            self.continuation = nil
            return current
        }

        continuation?.resume(with: result)
    }
}
