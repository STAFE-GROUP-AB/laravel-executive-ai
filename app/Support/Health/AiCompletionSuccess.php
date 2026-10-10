<?php

declare(strict_types=1);

namespace App\Support\Health;

use Carbon\CarbonImmutable;

/**
 * One succeeded model completion: which provider and model answered, and when.
 * No prompt, no response, no key — only what `ai_provider` and
 * `ai_last_success_at` need (health contract §3).
 */
final readonly class AiCompletionSuccess
{
    public function __construct(
        public string $provider,
        public string $model,
        public CarbonImmutable $at,
    ) {}

    /** @param array{provider: string, model: string, at: string} $row */
    public static function fromArray(array $row): self
    {
        return new self($row['provider'], $row['model'], CarbonImmutable::parse($row['at']));
    }

    /** @return array{provider: string, model: string, at: string} */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'model' => $this->model,
            'at' => $this->at->toRfc3339String(),
        ];
    }
}
