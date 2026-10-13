<?php

declare(strict_types=1);

namespace App\Security\Egress;

/**
 * Checklist row 16: every denial is recorded with the requested host, the
 * resolved address and the rule that fired — and **never** the full URL, whose
 * query may carry a token. The seam exists so the address rules stay a pure
 * table test with no database, while the product still writes the row.
 */
interface EgressDenialRecorder
{
    public function denied(EgressRule $rule, string $requestedHost, ?string $resolvedAddress, EgressPurpose $purpose): void;
}
