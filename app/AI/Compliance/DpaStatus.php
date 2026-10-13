<?php

declare(strict_types=1);

namespace App\AI\Compliance;

/**
 * ADR-007 D6 R3 `dpa_status`: whether the owner's organisation has executed the
 * provider's data-processing agreement. User attestation — the app cannot verify a
 * contract. `EU_RESTRICTED` admits a provider only at `Reviewed` or better.
 */
enum DpaStatus: string
{
    case NotReviewed = 'not_reviewed';
    case Reviewed = 'reviewed';
    case Executed = 'executed';

    public function satisfiesEuRestricted(): bool
    {
        return $this !== self::NotReviewed;
    }
}
