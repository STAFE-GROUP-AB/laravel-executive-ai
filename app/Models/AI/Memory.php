<?php

declare(strict_types=1);

namespace App\Models\AI;

use App\AI\Memory\MemoryKind;
use App\AI\Memory\MemoryScope;
use App\AI\Memory\MemoryStatus;
use App\Models\Concerns\BusinessObject;
use App\Models\Concerns\EncryptsSensitiveAttributes;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use InvalidArgumentException;

/**
 * One app-owned memory (§3.1 `memories`, blueprint §4). A row the model proposes is
 * `MemoryStatus::Proposed` and needs a reviewed operation before it becomes
 * `Active`; `Active` is the only status `active()` — and therefore the agent —
 * returns.
 *
 * `content` is **encrypted at rest** (S2-04): a memory about a natural person is
 * `sensitivity_class = personal`, and nothing matches on the content — the
 * single-active-memory guarantee is the index over `scope_key` and `memory_key`, and
 * `memories` has no full-text index.
 *
 * @property string $id
 * @property string $organization_id
 * @property MemoryScope $scope
 * @property string $scope_key
 * @property ?string $subject_type
 * @property ?string $subject_id
 * @property ?string $user_id
 * @property MemoryKind $kind
 * @property string $memory_key
 * @property string $content
 * @property ?float $confidence
 * @property MemoryStatus $status
 * @property string $written_by
 * @property ?CarbonImmutable $reviewed_at
 * @property ?string $reviewed_by_user_id
 * @property ?string $review_note
 * @property int $version
 * @property ?string $supersedes_id
 * @property ?CarbonImmutable $expires_at
 * @property ?CarbonImmutable $last_used_at
 * @property int $use_count
 * @property ?int $active_marker
 */
class Memory extends Model
{
    use BusinessObject;
    use EncryptsSensitiveAttributes;
    use SoftDeletes;

    /**
     * `active_marker` is a generated column: the database computes it from `status`.
     *
     * @var list<string>
     */
    protected $guarded = ['active_marker'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            ...$this->businessObjectCasts(),
            ...$this->encryptedAttributeCasts(),
            'scope' => MemoryScope::class,
            'kind' => MemoryKind::class,
            'status' => MemoryStatus::class,
            'confidence' => 'float',
            'reviewed_at' => 'immutable_datetime',
            'version' => 'integer',
            'expires_at' => 'immutable_datetime',
            'last_used_at' => 'immutable_datetime',
            'use_count' => 'integer',
            'active_marker' => 'integer',
        ];
    }

    /**
     * The canonical, non-null scope identity the unique index is built on:
     * `organization`, `user:<ulid>`, `customer:<ulid>`, `project:<ulid>`. A scope
     * that needs a subject must be given one — a nullable subject in the index would
     * constrain nothing, because both engines treat `NULL` as distinct.
     */
    public static function scopeKeyFor(MemoryScope $scope, ?string $subjectId = null): string
    {
        if (! $scope->needsSubject()) {
            return $scope->value;
        }

        if ($subjectId === null || trim($subjectId) === '') {
            throw new InvalidArgumentException("A {$scope->value}-scoped memory needs a subject id.");
        }

        return $scope->value.':'.$subjectId;
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    /** @return BelongsTo<self, $this> */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    /** @return HasMany<MemorySource, $this> */
    public function sources(): HasMany
    {
        return $this->hasMany(MemorySource::class, 'memory_id')->orderBy('position');
    }

    /**
     * The only rows the agent may read: reviewed, active and unexpired (§4).
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeActive(Builder $query, ?CarbonImmutable $now = null): Builder
    {
        $instant = $now ?? CarbonImmutable::now();

        return $query->where('status', MemoryStatus::Active->value)
            ->where(fn (Builder $inner): Builder => $inner
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', $instant));
    }

    public function isReviewed(): bool
    {
        return $this->reviewed_at !== null && $this->reviewed_by_user_id !== null;
    }
}
