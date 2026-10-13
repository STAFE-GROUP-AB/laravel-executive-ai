<?php

declare(strict_types=1);

namespace App\AI\Compliance;

/**
 * ADR-007 D6 R4 `tia_status`: the transfer impact assessment for third-country
 * transfers (§10.6). `EU_RESTRICTED` requires `Done` or `NotRequired`; a local
 * runtime is `NotRequired` by construction (ADR-004 D5 `none_required`).
 */
enum TiaStatus: string
{
    case NotRequired = 'not_required';
    case Pending = 'pending';
    case Done = 'done';

    public function satisfiesEuRestricted(): bool
    {
        return $this !== self::Pending;
    }
}
