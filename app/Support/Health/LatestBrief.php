<?php

declare(strict_types=1);

namespace App\Support\Health;

use App\Models\Brief;
use Carbon\CarbonImmutable;

/**
 * `brief_state` and `latest_brief` (health contract §4): the newest brief by
 * generated_at, fresh while now < stale_after, error when the brief
 * generator recorded a failed scheduled run after it.
 */
final class LatestBrief
{
    public function __construct(private readonly Heartbeats $heartbeats) {}

    public function resolve(): ?Brief
    {
        return Brief::query()->latestGenerated()->first();
    }

    public function state(?Brief $brief, CarbonImmutable $now): BriefState
    {
        if ($brief === null) {
            return BriefState::None;
        }

        $failedAt = $this->heartbeats->lastSeenAt(Heartbeats::BRIEF_GENERATION_ERROR);

        if ($failedAt !== null && $failedAt->greaterThan($brief->generated_at)) {
            return BriefState::Error;
        }

        return $brief->isStaleAt($now) ? BriefState::Stale : BriefState::Fresh;
    }

    /** @return array{id: string, version: int, generated_at: string, stale_after: string}|null */
    public function summary(?Brief $brief): ?array
    {
        if ($brief === null) {
            return null;
        }

        return [
            'id' => $brief->document_id,
            'version' => $brief->version,
            'generated_at' => $brief->generated_at->toRfc3339String(),
            'stale_after' => $brief->stale_after->toRfc3339String(),
        ];
    }
}
