// The wire form of ipc-contract §7 over `NSXPCConnection` (Mach service
// `<prefix>.supervisor.control`, declared in the launchd plist's `MachServices`).
//
// The `@objc` protocol carries JSON `Data` payloads and a sanitised error code string; the
// Swift-native `SupervisorControlling` is what both ends program against. Both ends set the
// A12 code-signing requirement on the connection (`setCodeSigningRequirement`); without a
// requirement — an unsigned build — the listener accepts nothing unless a DEBUG build opts in.

import Foundation

@objc protocol SupervisorControlXPCProtocol {
    func pair(client: String, reply: @escaping @Sendable (Data?, String?) -> Void)
    func resetPairing(reply: @escaping @Sendable (Data?, String?) -> Void)
    func services(reply: @escaping @Sendable (Data?, String?) -> Void)
    func restart(service: String, reply: @escaping @Sendable (String?) -> Void)
    func stopAll(reply: @escaping @Sendable (String?) -> Void)
    func startAll(reply: @escaping @Sendable (String?) -> Void)
    func elevate(sessionId: String, operation: String, nonce: String, reply: @escaping @Sendable (Data?, String?) -> Void)
    func storeProviderApiKey(provider: String, apiKey: String, reply: @escaping @Sendable (String?) -> Void)
}

enum SupervisorControlWire {
    static func machServiceName(bundlePrefix: String) -> String {
        "\(bundlePrefix).supervisor.control"
    }

    /// ipc-contract §7 — "both sides set the same requirement".
    static func codeSigningRequirement(teamIdentifier: String) -> String {
        "anchor apple generic and certificate leaf[subject.OU] = \"\(teamIdentifier)\""
    }

    static func encode<T: Encodable>(_ value: T) -> Data? {
        try? PairingGrant.encoder().encode(value)
    }

    static func decode<T: Decodable>(_ type: T.Type, from data: Data?) -> T? {
        guard let data else {
            return nil
        }

        return try? PairingGrant.decoder().decode(type, from: data)
    }

    /// Error codes on the wire. Never a path, never a value.
    static func code(for error: any Error) -> String {
        guard let error = error as? SupervisorControlError else {
            return "internal"
        }

        switch error {
        case .unreachable: return "unreachable"
        case .secrets: return "secrets"
        case .authenticationFailed: return "authentication_failed"
        case .authenticationUnavailable: return "authentication_unavailable"
        case .refused(let reason): return "refused: \(reason)"
        case .invalidArgument(let name): return "invalid_argument: \(name)"
        }
    }

    static func error(from code: String) -> SupervisorControlError {
        switch code {
        case "authentication_failed": return .authenticationFailed
        case "authentication_unavailable": return .authenticationUnavailable(code)
        case "secrets": return .secrets(code)
        default:
            if code.hasPrefix("refused: ") {
                return .refused(String(code.dropFirst("refused: ".count)))
            }

            if code.hasPrefix("invalid_argument: ") {
                return .invalidArgument(String(code.dropFirst("invalid_argument: ".count)))
            }

            return .unreachable(code)
        }
    }
}

/// The exported object: forwards each XPC call into the async service.
final class SupervisorControlExporter: NSObject, SupervisorControlXPCProtocol, @unchecked Sendable {
    private let service: any SupervisorControlling

    init(service: any SupervisorControlling) {
        self.service = service
    }

    func pair(client: String, reply: @escaping @Sendable (Data?, String?) -> Void) {
        guard let client = IpcClient(rawValue: client) else {
            reply(nil, "invalid_argument: client")
            return
        }

        let service = self.service
        Task {
            do {
                reply(SupervisorControlWire.encode(try await service.pair(client: client)), nil)
            } catch {
                reply(nil, SupervisorControlWire.code(for: error))
            }
        }
    }

    func resetPairing(reply: @escaping @Sendable (Data?, String?) -> Void) {
        let service = self.service
        Task {
            do {
                reply(SupervisorControlWire.encode(try await service.resetPairing()), nil)
            } catch {
                reply(nil, SupervisorControlWire.code(for: error))
            }
        }
    }

    func services(reply: @escaping @Sendable (Data?, String?) -> Void) {
        let service = self.service
        Task {
            do {
                reply(SupervisorControlWire.encode(try await service.services()), nil)
            } catch {
                reply(nil, SupervisorControlWire.code(for: error))
            }
        }
    }

    func restart(service name: String, reply: @escaping @Sendable (String?) -> Void) {
        guard let name = ServiceName(rawValue: name) else {
            reply("invalid_argument: service")
            return
        }

        let service = self.service
        Task {
            do {
                try await service.restart(service: name)
                reply(nil)
            } catch {
                reply(SupervisorControlWire.code(for: error))
            }
        }
    }

    func stopAll(reply: @escaping @Sendable (String?) -> Void) {
        let service = self.service
        Task {
            do {
                try await service.stopAll()
                reply(nil)
            } catch {
                reply(SupervisorControlWire.code(for: error))
            }
        }
    }

    func startAll(reply: @escaping @Sendable (String?) -> Void) {
        let service = self.service
        Task {
            do {
                try await service.startAll()
                reply(nil)
            } catch {
                reply(SupervisorControlWire.code(for: error))
            }
        }
    }

    func elevate(sessionId: String, operation: String, nonce: String, reply: @escaping @Sendable (Data?, String?) -> Void) {
        let service = self.service
        Task {
            do {
                reply(SupervisorControlWire.encode(try await service.elevate(sessionId: sessionId, operation: operation, nonce: nonce)), nil)
            } catch {
                reply(nil, SupervisorControlWire.code(for: error))
            }
        }
    }

    func storeProviderApiKey(provider: String, apiKey: String, reply: @escaping @Sendable (String?) -> Void) {
        guard let secret = TransientSecret(apiKey) else {
            reply("invalid_argument: api_key")
            return
        }

        let service = self.service
        Task {
            do {
                try await service.storeProviderApiKey(provider: provider, apiKey: secret)
                reply(nil)
            } catch {
                reply(SupervisorControlWire.code(for: error))
            }
        }
    }
}

/// The Supervisor's listener. Accepts a connection only when the peer satisfies the code-signing
/// requirement (ipc-contract §7); `allowUnsignedPeers` exists for DEBUG lab runs only.
final class SupervisorControlListener: NSObject, NSXPCListenerDelegate, @unchecked Sendable {
    private let service: any SupervisorControlling
    private let requirement: String?
    private let allowUnsignedPeers: Bool
    private let listener: NSXPCListener

    init(machServiceName: String, requirement: String?, service: any SupervisorControlling, allowUnsignedPeers: Bool = false) {
        self.service = service
        self.requirement = requirement
        self.allowUnsignedPeers = allowUnsignedPeers
        listener = NSXPCListener(machServiceName: machServiceName)
        super.init()
        listener.delegate = self
    }

    func start() {
        listener.resume()
    }

    func stop() {
        listener.invalidate()
    }

    func listener(_ listener: NSXPCListener, shouldAcceptNewConnection newConnection: NSXPCConnection) -> Bool {
        if let requirement {
            newConnection.setCodeSigningRequirement(requirement)
        } else if !allowUnsignedPeers {
            return false
        }

        newConnection.exportedInterface = NSXPCInterface(with: SupervisorControlXPCProtocol.self)
        newConnection.exportedObject = SupervisorControlExporter(service: service)
        newConnection.resume()

        return true
    }
}
