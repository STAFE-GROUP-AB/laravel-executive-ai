<?php

declare(strict_types=1);

namespace App\Models;

use App\Briefs\ItemKind;
use App\Briefs\Priority;
use App\Models\Concerns\BusinessObject;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $brief_id
 * @property string $item_id
 * @property int $position
 * @property ItemKind $kind
 * @property string $title
 * @property string $body
 * @property Priority $priority
 * @property ?CarbonImmutable $due_at
 * @property ?array<int, string> $source_refs
 * @property ?array{reason: string, confidence: float|int} $uncertainty
 * @property ?array<int, string> $action_ids
 * @property ?string $dedupe_key
 */
class BriefItem extends Model
{
    use BusinessObject;
    use SoftDeletes;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            ...$this->businessObjectCasts(),
            'position' => 'integer',
            'kind' => ItemKind::class,
            'priority' => Priority::class,
            'due_at' => 'immutable_datetime',
            'source_refs' => 'array',
            'uncertainty' => 'array',
            'action_ids' => 'array',
        ];
    }

    /** @return BelongsTo<Brief, $this> */
    public function brief(): BelongsTo
    {
        return $this->belongsTo(Brief::class);
    }
}
