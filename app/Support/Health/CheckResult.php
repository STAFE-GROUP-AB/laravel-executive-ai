<?php

declare(strict_types=1);

namespace App\Support\Health;

use Carbon\CarbonImmutable;

/**
 * One entry of `checks[]` in the ready document (health.schema.json `check`).
 */
final readonly class CheckResult
{
    public function __construct(
        public string $name,
        public CheckOutcome $outcome,
        public CarbonImmutable $checkedAt,
        public int $durationMs,
    ) {}

    public function isOk(): bool
    {
        return $this->outcome->status === CheckStatus::Ok;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $document = [
            'name' => $this->name,
            'status' => $this->outcome->status->value,
            'detail' => $this->outcome->detail,
            'checked_at' => $this->checkedAt->toRfc3339String(),
            'duration_ms' => $this->durationMs,
        ];

        if ($this->outcome->ageSeconds !== null) {
            $document['age_seconds'] = $this->outcome->ageSeconds;
        }

        if ($this->outcome->thresholdSeconds !== null) {
            $document['threshold_seconds'] = $this->outcome->thresholdSeconds;
        }

        return $document;
    }
}
