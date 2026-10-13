<?php

declare(strict_types=1);

namespace App\CRM\Commitments;

/**
 * `commitments.status`, vocabulary from packaging/fixtures/crm/crm.schema.json.
 */
enum CommitmentStatus: string
{
    case Open = 'open';
    case Kept = 'kept';
    case Missed = 'missed';
    case Withdrawn = 'withdrawn';
}
