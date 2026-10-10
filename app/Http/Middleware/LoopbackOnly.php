<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Health contract §6: both health endpoints refuse non-loopback source
 * addresses. The bundled server binds 127.0.0.1 only (ADR-001 D3); this is
 * the in-app half of that promise and holds under any front server.
 */
final class LoopbackOnly
{
    private const array LOOPBACK = ['127.0.0.1', '::1'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->ip(), self::LOOPBACK, true)) {
            return response()->json(['error' => 'loopback_only'], 403)->header('Cache-Control', 'no-store');
        }

        return $next($request);
    }
}
