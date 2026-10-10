// LaravelExecutiveAISupervisor — the one launchd job (ADR-001 D3), started by SMAppService from
// `Contents/Library/LaunchAgents/<prefix>.supervisor.plist`. Headless; owns the FrankenPHP
// children, the secrets broker and the GUI control channel. Exit codes: 0 after an orderly
// SIGTERM, 78 (EX_CONFIG) when the installer has not finished — launchd retries per
// `ThrottleInterval`.
//
// `LXAI_SUPERVISOR_ALLOW_UNSIGNED_PEERS=1` (DEBUG builds only) lets an unsigned lab build
// accept unsigned XPC and broker peers; a release build ignores it.

import Foundation

let identity = SupervisorIdentity.resolve()
SupervisorLog.configure(subsystem: identity.logSubsystem)

let environment = ProcessInfo.processInfo.environment
var allowUnsignedPeers = false

#if DEBUG
allowUnsignedPeers = environment["LXAI_SUPERVISOR_ALLOW_UNSIGNED_PEERS"] == "1"
#endif

let signatureChecker: any CodeSignatureChecking

do {
    signatureChecker = try SecStaticCodeSignatureChecker.fromRunningApp()
} catch {
    signatureChecker = FailClosedSupervisorSignatureChecker(reason: error.userFacingReason)
}

let configuration = SupervisorConfiguration(
    identity: identity,
    paths: InstallPaths.default(bundleIdentifier: identity.appBundleIdentifier),
    keychain: SecItemKeychainStore(accessGroup: identity.keychainAccessGroup),
    verifier: PackageVerifier(signatureChecker: signatureChecker),
    peerVerifier: StrictPeerVerifier(requirement: identity.codeSigningRequirement, allowUnsignedPeers: allowUnsignedPeers),
    authenticator: LocalAuthenticationDeviceOwner(),
    allowUnsignedXPCPeers: allowUnsignedPeers,
    supervision: ServiceSupervisor.Configuration(),
    makeProbe: { port, grant in LoopbackHealthProbe(port: port, grant: grant) }
)

let runtime = SupervisorRuntime(configuration: configuration)

signal(SIGTERM, SIG_IGN)
signal(SIGINT, SIG_IGN)

let terminationSource = DispatchSource.makeSignalSource(signal: SIGTERM, queue: .main)
terminationSource.setEventHandler {
    SupervisorLog.boot.notice("SIGTERM received; stopping services")

    Task {
        await runtime.shutdown()
        exit(0)
    }
}
terminationSource.resume()

let interruptSource = DispatchSource.makeSignalSource(signal: SIGINT, queue: .main)
interruptSource.setEventHandler {
    Task {
        await runtime.shutdown()
        exit(0)
    }
}
interruptSource.resume()

Task {
    do {
        try await runtime.boot()
        SupervisorLog.boot.notice("supervisor booted")
    } catch let error as SupervisorBootError {
        SupervisorLog.boot.error("supervisor cannot boot: \(error.userFacingReason, privacy: .public)")
        await runtime.shutdown()
        exit(78)
    } catch {
        SupervisorLog.boot.error("supervisor cannot boot: unexpected error")
        await runtime.shutdown()
        exit(78)
    }
}

dispatchMain()

/// The tool's copy of the app's fail-closed policy (InstallDependencies.swift is not shared):
/// no Team ID, no runtime — nothing is spawned from an unsigned Supervisor.
struct FailClosedSupervisorSignatureChecker: CodeSignatureChecking {
    let reason: String

    func validate(executableAt url: URL) throws {
        throw PackageVerificationError.noSigningPolicy(reason)
    }
}
