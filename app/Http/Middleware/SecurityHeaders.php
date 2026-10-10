<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser-hardening headers for every `web` response (open question A16,
 * threat model T-21 clickjacking and T-22 XSS second layer). Appended to the
 * web group in bootstrap/app.php; S1-04's app shell ships inside them.
 *
 * One nonce per request is handed to Vite, and Livewire reads the same one
 * (`Vite::cspNonce()`), so every script tag we emit carries it. `unsafe-eval`
 * is required by Alpine's expression evaluator inside Livewire 3 — it does
 * not re-enable inline scripts, which stay nonce-gated. `style-src-attr
 * 'unsafe-inline'` keeps S1-03's mail preview (inline styles by design)
 * rendering; `<style>` elements still need the nonce.
 *
 * The set is a floor, not an override: a response that already carries one
 * of these headers keeps it, so a route can ship a stricter or deliberately
 * different policy (the S1-03 mail preview answers `default-src 'none'` with
 * `frame-ancestors 'self'` so the PWA may embed it in a same-origin frame).
 */
final class SecurityHeaders
{
    public const string PERMISSIONS_POLICY = 'accelerometer=(), camera=(), display-capture=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), midi=(), payment=(), usb=(), interest-cohort=()';

    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        $defaults = [
            'Content-Security-Policy' => self::contentSecurityPolicy($nonce),
            'X-Frame-Options' => 'DENY',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'same-origin',
            'Permissions-Policy' => self::PERMISSIONS_POLICY,
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ];

        foreach ($defaults as $header => $value) {
            if (! $response->headers->has($header)) {
                $response->headers->set($header, $value);
            }
        }

        return $response;
    }

    public static function contentSecurityPolicy(string $nonce): string
    {
        $directives = [
            "default-src 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "frame-ancestors 'none'",
            "frame-src 'none'",
            "form-action 'self'",
            "script-src 'self' 'nonce-{$nonce}' 'unsafe-eval'",
            "style-src 'self' 'nonce-{$nonce}'",
            "style-src-attr 'unsafe-inline'",
            "img-src 'self' data: blob:",
            "font-src 'self' data:",
            "connect-src 'self'",
            "manifest-src 'self'",
            "worker-src 'self'",
            "media-src 'self'",
        ];

        return implode('; ', $directives);
    }
}
