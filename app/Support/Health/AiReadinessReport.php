<?php

declare(strict_types=1);

namespace App\Support\Health;

use Carbon\CarbonImmutable;

final readonly class AiReadinessReport
{
    public function __construct(
        public AiState $state,
        public ?string $provider,
        public ?CarbonImmutable $lastSuccessAt,
    ) {}

    public static function notConfigured(): self
    {
        return new self(AiState::NotConfigured, null, null);
    }

    public static function configuredUntested(string $provider): self
    {
        return new self(AiState::ConfiguredUntested, $provider, null);
    }

    /**
     * Only ConfiguredProviderAiReadiness (and its Sprint 2 successor over
     * `ai_runs`) may build this, from a recorded successful completion.
     */
    public static function ready(string $provider, CarbonImmutable $lastSuccessAt): self
    {
        return new self(AiState::Ready, $provider, $lastSuccessAt);
    }

    public function isReady(): bool
    {
        return $this->state === AiState::Ready;
    }
}
