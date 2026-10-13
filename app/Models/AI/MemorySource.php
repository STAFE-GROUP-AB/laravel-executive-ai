<?php

declare(strict_types=1);

namespace App\Models\AI;

use App\AI\Memory\MemorySourceType;
use App\Models\Concerns\BusinessObject;
use App\Models\CRM\Communication;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One piece of evidence a memory rests on (§3.1 `memory_sources`, §4.2 source
 * references). `excerpt` is external content: data, never instruction.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $memory_id
 * @property MemorySourceType $source_type
 * @property int $position
 * @property ?string $communication_id
 * @property ?string $ai_run_id
 * @property ?string $excerpt
 * @property ?CarbonImmutable $occurred_at
 */
class MemorySource extends Model
{
    use BusinessObject;
    use SoftDeletes;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            ...$this->businessObjectCasts(),
            'source_type' => MemorySourceType::class,
            'position' => 'integer',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Memory, $this> */
    public function memory(): BelongsTo
    {
        return $this->belongsTo(Memory::class, 'memory_id');
    }

    /** @return BelongsTo<Communication, $this> */
    public function communication(): BelongsTo
    {
        return $this->belongsTo(Communication::class, 'communication_id');
    }

    /** @return BelongsTo<AiRun, $this> */
    public function aiRun(): BelongsTo
    {
        return $this->belongsTo(AiRun::class, 'ai_run_id');
    }
}
