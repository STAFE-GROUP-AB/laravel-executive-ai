<?php

declare(strict_types=1);

namespace App\CRM\Commitments;

/**
 * `commitments.direction`: `owed_by_us` when the owner promised something,
 * `owed_to_us` when the counterpart did (packaging/fixtures/crm/crm.schema.json).
 */
enum CommitmentDirection: string
{
    case OwedByUs = 'owed_by_us';
    case OwedToUs = 'owed_to_us';
}
