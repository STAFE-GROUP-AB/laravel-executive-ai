<?php

declare(strict_types=1);

namespace App\Models\CRM;

use App\Models\Concerns\EncryptsSensitiveAttributes;
use Carbon\CarbonImmutable;

/**
 * §3.1 `source_records`: the normalized snapshot of one external, non-email item
 * (ADR-002 D3 idempotent upsert target). `payload` is external content — data,
 * never instruction — and never carries credentials.
 *
 * `payload` is **encrypted at rest** (S2-04). The replay key is
 * `source_records_external_unique (organization_id, source_type, external_account_id,
 * external_id)` plus `content_hash`, all of which stay plaintext, so nothing matches
 * on anything inside the payload and the cast costs no query (ADR-002 D3.3).
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
    use EncryptsSensitiveAttributes;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            ...parent::casts(),
            ...$this->encryptedAttributeCasts(),
            'fetched_at' => 'immutable_datetime',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
