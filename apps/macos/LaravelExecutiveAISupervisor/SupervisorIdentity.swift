// Who the Supervisor is: the bundle prefix (A12 placeholder), the app it belongs to, the Mach
// service it serves and the Team ID it verifies peers against. A bare tool has no
// `bundleIdentifier`, so the prefix comes from the enclosing `.app`'s Info.plist, with the
// `LXAI_BUNDLE_PREFIX` environment variable as the test/lab override and the xcconfig default
// as the last resort.

import Foundation
import Security

struct SupervisorIdentity: Equatable, Sendable {
    static let defaultBundlePrefix = "se.stafe.executive-ai"

    let bundlePrefix: String
    /// Team ID of the running process's signature; nil in an unsigned build.
    let teamIdentifier: String?

    var appBundleIdentifier: String { "\(bundlePrefix).app" }
    var machServiceName: String { SupervisorControlWire.machServiceName(bundlePrefix: bundlePrefix) }
    var plistName: String { "\(bundlePrefix).supervisor.plist" }
    var logSubsystem: String { "\(bundlePrefix).supervisor" }

    /// `<TEAMID>.se.stafe.executive-ai.secrets` (ADR-003 D1), nil when unsigned.
    var keychainAccessGroup: String? {
        teamIdentifier.map { "\($0).\(SecretService.prefix).secrets" }
    }

    /// The requirement every peer (XPC client, broker client) must satisfy; nil when unsigned.
    var codeSigningRequirement: String? {
        teamIdentifier.map(SupervisorControlWire.codeSigningRequirement(teamIdentifier:))
    }

    static func resolve(environment: [String: String] = ProcessInfo.processInfo.environment, executable: URL? = Bundle.main.executableURL) -> SupervisorIdentity {
        let prefix = environment["LXAI_BUNDLE_PREFIX"].flatMap { $0.isEmpty ? nil : $0 }
            ?? executable.flatMap(enclosingAppBundleIdentifier).map(bundlePrefix(fromAppIdentifier:))
            ?? defaultBundlePrefix

        return SupervisorIdentity(bundlePrefix: prefix, teamIdentifier: runningTeamIdentifier())
    }

    static func bundlePrefix(fromAppIdentifier identifier: String) -> String {
        identifier.hasSuffix(".app") ? String(identifier.dropLast(4)) : identifier
    }

    /// Walks up from `Contents/MacOS/<tool>` to the `.app` and reads its `CFBundleIdentifier`.
    static func enclosingAppBundleIdentifier(of executable: URL) -> String? {
        var url = executable.deletingLastPathComponent()

        while url.pathComponents.count > 1 {
            if url.pathExtension == "app", let bundle = Bundle(url: url), let identifier = bundle.bundleIdentifier {
                return identifier
            }

            url = url.deletingLastPathComponent()
        }

        return nil
    }

    static func runningTeamIdentifier() -> String? {
        var code: SecCode?

        guard SecCodeCopySelf([], &code) == errSecSuccess, let code else {
            return nil
        }

        var staticCode: SecStaticCode?

        guard SecCodeCopyStaticCode(code, [], &staticCode) == errSecSuccess, let staticCode else {
            return nil
        }

        var info: CFDictionary?

        guard SecCodeCopySigningInformation(staticCode, SecCSFlags(rawValue: kSecCSSigningInformation), &info) == errSecSuccess,
              let dictionary = info as? [String: Any],
              let team = dictionary[kSecCodeInfoTeamIdentifier as String] as? String, !team.isEmpty
        else {
            return nil
        }

        return team
    }
}
