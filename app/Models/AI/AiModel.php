<?php

declare(strict_types=1);

namespace App\Models\AI;

use App\AI\Providers\CapabilitySource;
use App\AI\Providers\ModelCapabilities;
use App\Models\Concerns\BusinessObject;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One model configured under a provider — ADR-007 D6's model register, and the
 * persisted half of ADR-004 D3's capability registry (§3.1 `ai_models`).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $ai_provider_id
 * @property string $model_id
 * @property string $display_name
 * @property bool $enabled
 * @property ?string $model_version
 * @property ?string $model_digest
 * @property ?string $quantization
 * @property ?string $parameter_size
 * @property bool $text
 * @property bool $streaming
 * @property bool $tools
 * @property bool $structured_output
 * @property bool $vision
 * @property bool $embeddings
 * @property ?int $embedding_dimensions
 * @property int $context_window
 * @property ?int $max_output_tokens
 * @property ?string $input_price_per_mtok
 * @property ?string $output_price_per_mtok
 * @property int $max_concurrency
 * @property CapabilitySource $capability_source
 * @property ?string $capability_source_url
 * @property ?CarbonImmutable $capabilities_verified_at
 * @property ?string $license_name
 * @property ?string $license_url
 * @property ?string $license_text_hash
 * @property ?CarbonImmutable $license_accepted_at
 * @property ?string $license_accepted_by_user_id
 */
class AiModel extends Model
{
    use BusinessObject;
    use SoftDeletes;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            ...$this->businessObjectCasts(),
            'enabled' => 'boolean',
            'text' => 'boolean',
            'streaming' => 'boolean',
            'tools' => 'boolean',
            'structured_output' => 'boolean',
            'vision' => 'boolean',
            'embeddings' => 'boolean',
            'embedding_dimensions' => 'integer',
            'context_window' => 'integer',
            'max_output_tokens' => 'integer',
            'max_concurrency' => 'integer',
            'capability_source' => CapabilitySource::class,
            'capabilities_verified_at' => 'immutable_datetime',
            'license_accepted_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<AiProvider, $this> */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'ai_provider_id');
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * The row as the §3 `capabilities(): ModelCapabilities` contract sees it. Prices
     * are read through `(float)` from `decimal(12,6)`, which Eloquent hands back as
     * a string so no rounding happens on the way out of the database.
     */
    public function toCapabilities(): ModelCapabilities
    {
        return new ModelCapabilities(
            modelId: $this->model_id,
            text: $this->text,
            streaming: $this->streaming,
            tools: $this->tools,
            structuredOutput: $this->structured_output,
            vision: $this->vision,
            embeddings: $this->embeddings,
            embeddingDimensions: $this->embedding_dimensions,
            contextWindow: $this->context_window,
            maxOutputTokens: $this->max_output_tokens,
            inputPricePerMTok: $this->input_price_per_mtok === null ? null : (float) $this->input_price_per_mtok,
            outputPricePerMTok: $this->output_price_per_mtok === null ? null : (float) $this->output_price_per_mtok,
            maxConcurrency: $this->max_concurrency,
            source: $this->capability_source,
            verifiedAt: $this->capabilities_verified_at === null
                ? null
                : new DateTimeImmutable($this->capabilities_verified_at->toIso8601String()),
        );
    }

    /**
     * ADR-004 D7's provenance triple, as it is copied onto every `ai_runs` row.
     *
     * @return array{model_id: string, model_digest: ?string, quantization: ?string}
     */
    public function provenance(): array
    {
        return [
            'model_id' => $this->model_id,
            'model_digest' => $this->model_digest,
            'quantization' => $this->quantization,
        ];
    }
}
