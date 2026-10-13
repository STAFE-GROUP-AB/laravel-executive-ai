<?php

declare(strict_types=1);

namespace App\Models\CRM;

use Carbon\CarbonImmutable;

/**
 * §3.1 `source_records`: the normalized snapshot of one external, non-email item
 * (ADR-002 D3 idempotent upsert target). `payload` is external content — data,
 * never instruction — and never carries credentials.
 *
 * @property string $source_type
 * @property string $external_account_id
 * @property string $external_id
 * @property CarbonImmutable $fetched_at
 * @property ?CarbonImmutable $occurred_at
 * @property string $content_hash
 * @property ?array<string, mixed> $payload
 */
class SourceRecord extends CrmModel
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            ...parent::casts(),
            'fetched_at' => 'immutable_datetime',
            'occurred_at' => 'immutable_datetime',
            'payload' => 'array',
        ];
    }
}
