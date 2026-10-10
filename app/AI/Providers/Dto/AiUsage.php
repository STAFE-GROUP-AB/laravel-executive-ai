<?php

declare(strict_types=1);

namespace App\AI\Providers\Dto;

/**
 * Token and cost accounting for one request (ADR-004 D2 row 8, D7 provenance).
 * Token fields mirror `Laravel\Ai\Responses\Data\TextUsage` one-to-one; `costEur`
 * is ours: tokens x the per-model price from the registry, `0.0` for local models,
 * `null` when the price is unknown.
 */
final readonly class AiUsage
{
    public function __construct(
        public int $inputTokens,
        public int $outputTokens,
        public ?int $cacheReadInputTokens,
        public ?int $cacheWriteInputTokens,
        public ?int $reasoningTokens,
        public ?float $costEur,
    ) {}

    public function totalTokens(): int
    {
        return $this->inputTokens + $this->outputTokens;
    }
}
