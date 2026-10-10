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

    public function isReady(): bool
    {
        return $this->state === AiState::Ready;
    }
}
