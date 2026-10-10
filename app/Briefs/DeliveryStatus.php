<?php

declare(strict_types=1);

namespace App\Briefs;

/**
 * Status of one delivery attempt. At most one `sent` per (channel, recipient)
 * is enforced by the `brief_deliveries_single_sent_unique` index.
 */
enum DeliveryStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
