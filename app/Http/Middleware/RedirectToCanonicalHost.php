<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR-006 D3: `localhost` is the one browser origin on loopback, and a
 * browser request that arrives on `127.0.0.1` is sent to the same path on
 * `localhost`. Both names reach the same socket, but WebAuthn refuses an IP
 * literal as a relying-party id (auth.md §7.1), so passkeys exist on one of
 * them only — and session cookies are host-only, so without this a person
 * could hold a session on one name and a passkey on the other and never
 * understand why the passkey is not offered. One browser identity instead.
 *
 * This is the *browser* half of D3 and it must never touch the other half:
 * `/ipc/*` is the literal `127.0.0.1` by contract (ipc-contract §1.1, so no
 * resolver can redirect the client) and `/health/*` is what the Supervisor
 * probes. Both live in the `api` group, which this middleware is not part
 * of; the path guard below repeats the exclusion so that registering it
 * more widely cannot silently break the Mac app. A client that is handed a
 * redirect in those channels treats it as a hijacked port and reports the
 * core unreachable (ipc-contract §5.2), which is the failure this prevents.
 *
 * No attempt is made to carry the session across: the cookie is host-only by
 * design (D3 `SESSION_DOMAIN = null`) and migrating it would mean minting a
 * session for a host the browser has not yet been seen on. The person signs
 * in on the canonical host, which is where the product sends them anyway.
 */
final class RedirectToCanonicalHost
{
    /** The canonical browser host on loopback: a name, so it can be a relying-party id. */
    public const string CANONICAL_HOST = 'localhost';

    /**
     * The loopback hosts that are redirected. Exactly the IP literals
     * `TrustLoopbackHosts` admits — no other host can reach this code, and a
     * named host (the tailnet origin, E4-1) is already canonical.
     *
     * @var list<string>
     */
    private const array REDIRECTED_HOSTS = ['127.0.0.1', '::1', '[::1]'];

    /** Paths that are never redirected, whatever group this middleware is registered in. */
    private const array NEVER_REDIRECTED = ['ipc/*', 'health/*'];

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->shouldRedirect($request)) {
            return $this->redirect($request);
        }

        return $next($request);
    }

    private function shouldRedirect(Request $request): bool
    {
        if ($request->is(...self::NEVER_REDIRECTED)) {
            return false;
        }

        return in_array(strtolower($request->getHost()), self::REDIRECTED_HOSTS, true);
    }

    /**
     * Same scheme, same port, same path, same query — only the host changes.
     * `302` for a navigation; `307` for anything else, because a `302` turns
     * a POST into a GET and would drop the body on the floor.
     */
    private function redirect(Request $request): RedirectResponse
    {
        $port = $request->getPort();
        $authority = self::CANONICAL_HOST.($this->isDefaultPort($request, $port) ? '' : ':'.$port);
        $target = $request->getScheme().'://'.$authority.$request->getRequestUri();

        $status = $request->isMethod('GET') || $request->isMethod('HEAD') ? 302 : 307;

        return new RedirectResponse($target, $status, ['Cache-Control' => 'no-store']);
    }

    private function isDefaultPort(Request $request, ?int $port): bool
    {
        if ($port === null) {
            return true;
        }

        return $request->isSecure() ? $port === 443 : $port === 80;
    }
}
