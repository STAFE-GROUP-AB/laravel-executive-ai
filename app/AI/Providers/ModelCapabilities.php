<?php

declare(strict_types=1);

namespace App\AI\Providers;

use DateTimeImmutable;

/**
 * Per-model capability record (blueprint §3 `capabilities(): ModelCapabilities`;
 * ADR-004 D3). This is our own registry: `laravel/ai` 1.2.0 exposes provider-level
 * contracts only (`TextProvider`, `EmbeddingProvider`, ...) and carries no per-model
 * data, so nothing here wraps an SDK type.
 *
 * Prices are per million tokens in EUR; `null` means unknown, `0.0` means local.
 */
final readonly class ModelCapabilities
{
    public function __construct(
        public string $modelId,
        public bool $text,
        public bool $streaming,
        public bool $tools,
        public bool $structuredOutput,
        public bool $vision,
        public bool $embeddings,
        public ?int $embeddingDimensions,
        public int $contextWindow,
        public ?int $maxOutputTokens,
        public ?float $inputPricePerMTok,
        public ?float $outputPricePerMTok,
        public int $maxConcurrency,
        public CapabilitySource $source,
        public ?DateTimeImmutable $verifiedAt,
    ) {}

    /**
     * ADR-004 D3 fail-closed default for a model no registry, probe or override knows:
     * text and streaming only, 4096 context, nothing else. A router that receives this
     * refuses every job needing more; it never degrades the job.
     */
    public static function unknown(string $modelId): self
    {
        return new self(
            modelId: $modelId,
            text: true,
            streaming: true,
            tools: false,
            structuredOutput: false,
            vision: false,
            embeddings: false,
            embeddingDimensions: null,
            contextWindow: 4096,
            maxOutputTokens: null,
            inputPricePerMTok: null,
            outputPricePerMTok: null,
            maxConcurrency: 1,
            source: CapabilitySource::Unknown,
            verifiedAt: null,
        );
    }

    public function supports(ModelCapability $capability): bool
    {
        return match ($capability) {
            ModelCapability::Text => $this->text,
            ModelCapability::Streaming => $this->streaming,
            ModelCapability::Tools => $this->tools,
            ModelCapability::StructuredOutput => $this->structuredOutput,
            ModelCapability::Vision => $this->vision,
            ModelCapability::Embeddings => $this->embeddings,
        };
    }

    /**
     * The subset of `$required` this model lacks; empty means the job may run.
     *
     * @param  list<ModelCapability>  $required
     * @return list<ModelCapability>
     */
    public function missing(array $required): array
    {
        return array_values(array_filter(
            $required,
            fn (ModelCapability $capability): bool => ! $this->supports($capability),
        ));
    }
}
