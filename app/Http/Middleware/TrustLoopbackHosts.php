<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustHosts;

/**
 * ADR-006 D3 / threat model T-18: the core answers only to its loopback host
 * names (and, once remote access is enabled, the exact tailnet DNS name that
 * E4-1 writes to config/remote.php). Any other Host header — DNS rebinding
 * from a hostile page, another local process spoofing X-Forwarded-Host — is
 * refused before ResolveRequestOrigin derives the URL root from it.
 *
 * Laravel's stock TrustHosts switches itself off in the `local` environment
 * and under tests; the installed core runs as `local`, so this subclass
 * enforces the list unconditionally. Never a wildcard, never subdomains.
 */
final class TrustLoopbackHosts extends TrustHosts
{
    /** @var list<string> */
    public const array LOOPBACK = ['^127\.0\.0\.1$', '^localhost$', '^\[::1\]$'];

    /** @return list<string> */
    public function hosts(): array
    {
        $remote = config('remote.trusted_hosts', []);

        return [...self::LOOPBACK, ...array_values(array_filter(is_array($remote) ? $remote : [], is_string(...)))];
    }

    protected function shouldSpecifyTrustedHosts(): bool
    {
        return true;
    }
}
