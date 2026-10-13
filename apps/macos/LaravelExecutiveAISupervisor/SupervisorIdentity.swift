// Who the Supervisor is: the bundle prefix (A12 placeholder), the app it belongs to, the Mach
// service it serves and the Team ID it verifies peers against. The prefix comes from the *app's*
// bundle identifier, not the Supervisor's own: since A24 the Supervisor is a helper application
// bundle inside the app (`Contents/Helpers/LaravelExecutiveAISupervisor.app`), so its own
// identifier is `<prefix>.supervisor` and the innermost enclosing `.app` is itself. The app is
// found through the helper's `LXAIAppBundleIdentifier` Info.plist key, falling back to the
// outermost enclosing `.app`, with the `LXAI_BUNDLE_PREFIX` environment variable as the test/lab
// override and the xcconfig default as the last resort.

import Foundation
import Security

struct SupervisorIdentity: Equatable, Sendable {
    static let defaultBundlePrefix = "se.stafe.executive-ai"

    /// Info.plist key in the helper bundle naming the app it is embedded in (A24).
    static let appBundleIdentifierInfoKey = "LXAIAppBundleIdentifier"

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

    /// The bundle identifier of the **app** the executable belongs to, resolved from
    /// `…/LaravelExecutiveAI.app/Contents/Helpers/LaravelExecutiveAISupervisor.app/Contents/MacOS/…`.
    ///
    /// Walking up to the *first* `.app` would find the helper itself and answer
    /// `<prefix>.supervisor`, which would then make every derived name wrong (the Mach service,
    /// the plist name, the Application Support directory). So the helper's own
    /// `LXAIAppBundleIdentifier` key wins when it is present, and the fallback is the *outermost*
    /// enclosing `.app` — the app that contains the helper, never the helper.
    static func enclosingAppBundleIdentifier(of executable: URL) -> String? {
        var url = executable.deletingLastPathComponent()
        var declared: String?
        var outermost: String?

        while url.pathComponents.count > 1 {
            if url.pathExtension == "app", let bundle = Bundle(url: url) {
                if declared == nil,
                   let value = bundle.object(forInfoDictionaryKey: appBundleIdentifierInfoKey) as? String,
                   !value.isEmpty {
                    declared = value
                }

                if let identifier = bundle.bundleIdentifier {
                    outermost = identifier
                }
            }

            url = url.deletingLastPathComponent()
        }

        return declared ?? outermost
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
