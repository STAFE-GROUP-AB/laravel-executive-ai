<?php

declare(strict_types=1);

namespace App\Models\AI;

use App\AI\Providers\Dto\AiErrorKind;
use App\AI\Providers\Dto\AiFinishReason;
use App\AI\Routing\PrivacyMode;
use App\AI\Runs\RunOutcome;
use App\Models\Concerns\BusinessObject;
use App\Models\Organization;
use App\Support\Json\Jcs;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

/**
 * One AI run with ADR-004 D7's thirteen provenance fields (§3.1 `ai_runs`).
 * Append-only: no soft delete, and only the retention job removes a row.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $trace_id
 * @property string $job_class
 * @property ?string $ai_provider_id
 * @property string $provider
 * @property ?string $ai_model_id
 * @property string $model_id
 * @property ?string $model_digest
 * @property ?string $quantization
 * @property PrivacyMode $privacy_mode
 * @property ?string $region_evidence_id
 * @property string $prompt_hash
 * @property array<string, mixed> $capabilities_snapshot
 * @property int $input_tokens
 * @property int $output_tokens
 * @property ?int $cache_read_input_tokens
 * @property ?int $cache_write_input_tokens
 * @property ?int $reasoning_tokens
 * @property int $total_tokens
 * @property ?string $cost_eur
 * @property ?string $max_cost_eur
 * @property ?int $wall_time_ms
 * @property ?int $max_steps
 * @property int $steps_used
 * @property ?int $max_time_seconds
 * @property ?int $max_output_tokens
 * @property ?string $prompt_redacted
 * @property ?string $response_redacted
 * @property CarbonImmutable $started_at
 * @property ?CarbonImmutable $finished_at
 * @property ?CarbonImmutable $cancelled_at
 * @property ?string $cancellation_source
 * @property ?AiFinishReason $finish_reason
 * @property ?AiErrorKind $error_kind
 * @property ?string $error_message
 * @property RunOutcome $outcome
 * @property string $dedupe_key
 * @property CarbonImmutable $dedupe_window_start
 * @property ?int $dedupe_marker
 */
class AiRun extends Model
{
    use BusinessObject;

    /**
     * `dedupe_marker` is a generated column: the database computes it from
     * `outcome`, writing it is an error on both engines, and it is guarded so no
     * mass assignment can try.
     *
     * @var list<string>
     */
    protected $guarded = ['dedupe_marker'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            ...$this->businessObjectCasts(),
            'capabilities_snapshot' => 'array',
            'privacy_mode' => PrivacyMode::class,
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cache_read_input_tokens' => 'integer',
            'cache_write_input_tokens' => 'integer',
            'reasoning_tokens' => 'integer',
            'total_tokens' => 'integer',
            'wall_time_ms' => 'integer',
            'max_steps' => 'integer',
            'steps_used' => 'integer',
            'max_time_seconds' => 'integer',
            'max_output_tokens' => 'integer',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'dedupe_window_start' => 'immutable_datetime',
            'finish_reason' => AiFinishReason::class,
            'error_kind' => AiErrorKind::class,
            'outcome' => RunOutcome::class,
            'dedupe_marker' => 'integer',
        ];
    }

    /**
     * ADR-004 D7's dedupe key, `sha256(job_class, normalized_input, model_id)`.
     *
     * The three parts are length-prefixed before hashing so no boundary can be
     * shifted (one job class ending where another's input begins), and the
     * normalized input is canonicalised with RFC 8785 (`App\Support\Json\Jcs`, the
     * rule the brief's `content_hash` uses) so two logically identical requests
     * with differently ordered keys produce the same key.
     */
    public static function dedupeKey(string $jobClass, mixed $normalizedInput, string $modelId): string
    {
        $buffer = '';

        foreach ([$jobClass, Jcs::canonicalize($normalizedInput), $modelId] as $part) {
            $buffer .= strlen($part)."\x1e".$part."\x1e";
        }

        return 'sha256:'.hash('sha256', $buffer);
    }

    /**
     * The UTC start of the `dedupe_window_minutes` bucket an instant falls in
     * (ADR-004 D7, default 60). The unique index is over the bucket, not over the
     * key alone, so a request repeated after the window inserts normally instead of
     * colliding with a run that is no longer a cache hit.
     */
    public static function dedupeWindowStart(DateTimeInterface $at, int $windowMinutes = 60): CarbonImmutable
    {
        if ($windowMinutes < 1) {
            throw new InvalidArgumentException('A dedupe window is at least one minute.');
        }

        $instant = CarbonImmutable::instance($at)->utc()->startOfMinute();
        $window = $windowMinutes * 60;

        return $instant->subSeconds($instant->getTimestamp() % $window);
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<AiProvider, $this> */
    public function aiProvider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'ai_provider_id');
    }

    /** @return BelongsTo<AiModel, $this> */
    public function aiModel(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'ai_model_id');
    }

    /**
     * The `ai_providers` row whose ADR-004 D5 evidence the run was routed on. Kept
     * separate from `aiProvider()` because the evidence that justified a run must
     * stay readable even after the provider is reconfigured.
     *
     * @return BelongsTo<AiProvider, $this>
     */
    public function regionEvidence(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'region_evidence_id');
    }

    /** @return HasMany<ToolRun, $this> */
    public function toolRuns(): HasMany
    {
        return $this->hasMany(ToolRun::class, 'ai_run_id')->orderBy('step');
    }
}
