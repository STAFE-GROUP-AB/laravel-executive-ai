<?php

declare(strict_types=1);

namespace App\Security\Egress;

/**
 * Name resolution as a seam, so the guard can be tested without a network and
 * so "resolve once" is a property of one object rather than a habit spread
 * across call sites (checklist row 12, threat model T-25).
 *
 * An implementation must resolve a name **once per request** and return every
 * A and AAAA record it got. Returning one address when several exist would hide
 * exactly the disagreement the guard refuses on.
 */
interface HostResolver
{
    /**
     * Every address the canonical host resolves to, in canonical textual form.
     * An empty list means nothing answered; it is never a reason to connect to
     * the name instead.
     *
     * @return list<string>
     */
    public function resolve(string $canonicalHost): array;
}
