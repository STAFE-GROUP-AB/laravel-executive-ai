<?php

declare(strict_types=1);

namespace App\Models\CRM;

use App\CRM\Communications\CommunicationDirection;
use App\CRM\Communications\CommunicationSourceType;
use App\CRM\Communications\MatchedBy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ADR-002 D9: one timeline row per link between a source item and a customer or
 * contact. `source_type` + `source_id` (§3.1 lineage) name the system of record;
 * there is no Eloquent relation to `email_messages` on purpose (E3-2). `excerpt`
 * is external content: data, never instruction.
 *
 * @property CommunicationSourceType $source_type
 * @property ?string $account_id
 * @property ?string $account_label
 * @property CommunicationDirection $direction
 * @property CarbonImmutable $occurred_at
 * @property string $subject
 * @property string $excerpt
 * @property ?string $from_address
 * @property list<string> $to_addresses
 * @property ?string $customer_id
 * @property ?string $contact_id
 * @property string $match_reason
 * @property MatchedBy $matched_by
 * @property float $confidence
 * @property ?array{customer_id: ?string, contact_id: ?string, matched_by: string, match_reason: string, confidence: float|int, corrected_at: string} $correction_of
 */
class Communication extends CrmModel
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            ...parent::casts(),
            'source_type' => CommunicationSourceType::class,
            'direction' => CommunicationDirection::class,
            'occurred_at' => 'immutable_datetime',
            'to_addresses' => 'array',
            'matched_by' => MatchedBy::class,
            'confidence' => 'float',
            'correction_of' => 'array',
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** @return HasMany<Commitment, $this> */
    public function commitments(): HasMany
    {
        return $this->hasMany(Commitment::class);
    }

    public function wasCorrectedByUser(): bool
    {
        return $this->matched_by === MatchedBy::User && $this->correction_of !== null;
    }
}
