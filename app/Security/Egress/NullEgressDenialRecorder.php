<?php

declare(strict_types=1);

namespace App\Security\Egress;

/**
 * Records nothing. Used by the address table tests, where the point is the
 * decision and not the row, and as the guard's own default so constructing a
 * guard never needs a database. The product binds
 * `AuditEgressDenialRecorder`, and `SsrfGuardTest` proves the audited path
 * separately.
 */
final class NullEgressDenialRecorder implements EgressDenialRecorder
{
    public function denied(EgressRule $rule, string $requestedHost, ?string $resolvedAddress, EgressPurpose $purpose): void
    {
        //
    }
}
