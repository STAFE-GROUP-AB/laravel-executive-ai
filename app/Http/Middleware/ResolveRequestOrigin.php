<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR-006 D3: one process serves two origins — loopback HTTP for the Mac
 * app and HTTPS through Tailscale Serve for the tailnet — so the session
 * cookie's `secure` flag and the URL root are decided per request, never
 * statically. Runs first in the `web` group, before StartSession reads the
 * session config. On loopback today this yields a non-secure, host-only,
 * HttpOnly, SameSite=lax cookie; the TrustProxies/TrustHosts half of D3
 * lands with the remote origin (E4-1).
 */
final class ResolveRequestOrigin
{
    public const string HEADER = 'X-Lxai-Origin';

    public function handle(Request $request, Closure $next): Response
    {
        config(['session.secure' => $request->isSecure()]);

        URL::forceRootUrl($request->getSchemeAndHttpHost());
        URL::forceScheme($request->getScheme());

        $response = $next($request);

        $response->headers->set(self::HEADER, $request->getHttpHost());

        return $response;
    }
}
