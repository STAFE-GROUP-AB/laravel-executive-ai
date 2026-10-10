<?php

declare(strict_types=1);

namespace App\Support\Cancellation;

use Carbon\CarbonImmutable;

/**
 * What the pause switch reports. `inFlightRunsCancelled` counts the
 * `ai_runs` a pause interrupted; there is no run ledger in Sprint 1, so it
 * is 0 until the agent loop (E2-5) records runs.
 */
final readonly class AiPauseStatus
{
    public function __construct(
        public bool $paused,
        public CarbonImmutable $changedAt,
        public ?CarbonImmutable $pausedSince,
        public ?string $reason,
        public bool $changed = false,
        public int $inFlightRunsCancelled = 0,
    ) {}

    /** @return array{ai_actions: string, changed_at: string, paused_since: ?string, in_flight_runs_cancelled: int} */
    public function toIpcData(string $timezone): array
    {
        return [
            'ai_actions' => $this->paused ? 'paused' : 'running',
            'changed_at' => $this->changedAt->setTimezone($timezone)->format('Y-m-d\TH:i:sP'),
            'paused_since' => $this->pausedSince?->setTimezone($timezone)->format('Y-m-d\TH:i:sP'),
            'in_flight_runs_cancelled' => $this->paused ? $this->inFlightRunsCancelled : 0,
        ];
    }
}
