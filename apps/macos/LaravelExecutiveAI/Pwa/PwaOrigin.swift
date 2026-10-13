// The canonical browser origin for the PWA (ADR-006 D3): `http://localhost:<port>`.
//
// This is deliberately NOT the IPC origin. The IPC client addresses the literal `127.0.0.1`
// (ipc-contract §1.1) so that no resolver configuration can point it somewhere else, and
// `IpcOriginClientTest` pins that the client never so much as names `localhost`. A browser cannot
// use that host for a passkey: WebAuthn refuses an IP literal as a relying-party id, so on
// `127.0.0.1` the §11 passkey requirement is unreachable (docs/architecture/auth.md §7.1).
// `localhost` is the same loopback socket, is a secure context, and is a legal relying-party id —
// so every URL this app hands to a browser is built here, and nowhere else.
//
// The Laravel core redirects a browser request that still arrives on `127.0.0.1` to the same path
// on `localhost` (`App\Http\Middleware\RedirectToCanonicalHost`), which covers a bookmark, a
// hand-typed address and a deep link the core itself produced. This type exists so the app does
// not rely on that redirect for the URLs it opens itself: session cookies are host-only, and one
// browser identity is the point.

import Foundation

enum PwaOrigin {
    /// The host every browser URL the app opens is built on.
    static let host = "localhost"

    /// The loopback hosts a browser URL is rewritten away from. A named host — the Tailscale Serve
    /// origin (ADR-006 D2) — is already canonical and is left exactly as it is.
    static let rewrittenHosts = ["127.0.0.1", "::1", "[::1]"]

    /// `http://localhost:<port>` — the installer's Done screen, which knows the preflight port but
    /// has no IPC client yet.
    static func origin(port: Int) -> URL? {
        var components = URLComponents()
        components.scheme = "http"
        components.host = host
        components.port = port

        return components.url
    }

    /// The same URL on the canonical host: scheme, port, path, query and fragment untouched. A URL
    /// that is not on a loopback IP literal is returned unchanged, so this is safe to apply to a
    /// deep link the core produced (`PendingApproval.openUrl`) whatever origin it was built on.
    static func canonical(_ url: URL) -> URL {
        guard var components = URLComponents(url: url, resolvingAgainstBaseURL: false),
              let currentHost = components.host,
              rewrittenHosts.contains(currentHost.lowercased())
        else {
            return url
        }

        components.host = host

        return components.url ?? url
    }
}
