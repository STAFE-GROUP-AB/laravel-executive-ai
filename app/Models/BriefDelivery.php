<?php

declare(strict_types=1);

namespace App\Models;

use App\Briefs\DeliveryChannel;
use App\Briefs\DeliveryStatus;
use App\Models\Concerns\BusinessObject;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One delivery attempt. Append-only: rows are never updated after insert.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $brief_id
 * @property string $idempotency_key
 * @property int $position
 * @property DeliveryChannel $channel
 * @property string $recipient
 * @property int $attempt
 * @property DeliveryStatus $status
 * @property ?string $message_id
 * @property ?CarbonImmutable $sent_at
 * @property ?string $error
 */
class BriefDelivery extends Model
{
    use BusinessObject;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            ...$this->businessObjectCasts(),
            'channel' => DeliveryChannel::class,
            'position' => 'integer',
            'attempt' => 'integer',
            'status' => DeliveryStatus::class,
            'sent_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Brief, $this> */
    public function brief(): BelongsTo
    {
        return $this->belongsTo(Brief::class);
    }
}
