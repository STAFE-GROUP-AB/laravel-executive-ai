<?php

declare(strict_types=1);

namespace App\Approvals\Policies;

/**
 * The decision is about a frozen payload, and this payload is not the frozen one:
 * `proposal_hash` no longer matches the content, or a verdict is being used against
 * content it was not issued for (`PolicyVerdict::binds()` returned false).
 *
 * The §11 rule it enforces is the one ADR-005 D1.6 states for the executing
 * provider — recompute the hash and refuse on mismatch — applied one layer earlier,
 * so an edited payload invalidates the allow instead of riding on it.
 */
final class PayloadNotFrozenException extends PolicyRefusal
{
    protected const string EXPLANATION = 'the payload is not the one that was frozen and hashed';
}
