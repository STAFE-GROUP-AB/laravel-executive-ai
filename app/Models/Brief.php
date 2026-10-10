<?php

declare(strict_types=1);

namespace App\Models;

use App\Briefs\DeliveryState;
use App\Models\Concerns\BusinessObject;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One frozen version of an Executive Brief. `id` is the version row,
 * `document_id` the identity across versions (brief schema `id`).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $document_id
 * @property int $version
 * @property string $schema_version
 * @property CarbonImmutable $generated_at
 * @property CarbonImmutable $period_start
 * @property CarbonImmutable $period_end
 * @property CarbonImmutable $stale_after
 * @property string $utc_offset
 * @property string $language
 * @property string $subject
 * @property string $summary
 * @property array<int, array<string, mixed>> $sources
 * @property array<int, string> $action_ids
 * @property array<string, mixed> $model_provenance
 * @property string $content_hash
 * @property DeliveryState $delivery_state
 * @property string $idempotency_key
 */
class Brief extends Model
{
    use BusinessObject;
    use SoftDeletes;

    public const string SCHEMA_VERSION = '1.0.0';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            ...$this->businessObjectCasts(),
            'version' => 'integer',
            'generated_at' => 'immutable_datetime',
            'period_start' => 'immutable_datetime',
            'period_end' => 'immutable_datetime',
            'stale_after' => 'immutable_datetime',
            'sources' => 'array',
            'action_ids' => 'array',
            'model_provenance' => 'array',
            'delivery_state' => DeliveryState::class,
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return HasMany<BriefItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(BriefItem::class)->orderBy('position');
    }

    /** @return HasMany<BriefDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(BriefDelivery::class)->orderBy('position');
    }

    /** @param Builder<Brief> $query */
    public function scopeLatestGenerated(Builder $query): void
    {
        $query->orderByDesc('generated_at')->orderByDesc('version');
    }

    public function isPlaceholder(): bool
    {
        return ($this->model_provenance['provider'] ?? null) === 'none';
    }

    public function isStaleAt(CarbonImmutable $now): bool
    {
        return $now->greaterThanOrEqualTo($this->stale_after);
    }
}
