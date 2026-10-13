<?php

declare(strict_types=1);

namespace App\Security\Egress;

/**
 * The resolver that never asks the network: `localhost` and `*.localhost`
 * answer from RFC 6761 §6.3's reserved meaning, and **every other name resolves
 * to nothing**, which the guard reads as `Unresolvable` and refuses.
 *
 * Two jobs, both deliberate.
 *
 * It is the fail-closed default wherever a guard is constructed without the
 * container — `ModelRouter`'s and `ProviderRegistry`'s default arguments — so a
 * caller that forgot to wire a resolver gets "no name resolves" rather than
 * "every name resolves through whatever the OS says".
 *
 * And it is the resolver bound under `php artisan test`. The repository's rule
 * is that **no test opens a real socket**, and a DNS lookup is a socket: a
 * suite that resolved `models.example.test` for real would be asserting against
 * whatever the developer's resolver happened to answer — including an ISP that
 * hijacks NXDOMAIN — and would be slow and flaky as well as dishonest. A test
 * that needs a name to resolve binds its own fake and says what the answer is.
 */
final class LoopbackHostResolver implements HostResolver
{
    /** @return list<string> */
    public function resolve(string $canonicalHost): array
    {
        return $this->isReservedLoopbackName($canonicalHost) ? ['127.0.0.1', '::1'] : [];
    }

    /**
     * RFC 6761 §6.3: `localhost` and any name under it are reserved and resolve
     * to loopback without consulting DNS. We treat that as a MUST rather than
     * the RFC's SHOULD, so a hostile answer for the one name our local model
     * endpoints are configured under cannot exist.
     */
    public function isReservedLoopbackName(string $canonicalHost): bool
    {
        return $canonicalHost === 'localhost' || str_ends_with($canonicalHost, '.localhost');
    }
}
