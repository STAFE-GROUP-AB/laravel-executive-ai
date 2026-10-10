<?php

declare(strict_types=1);

namespace App\Support\Health\Checks;

use App\Support\Health\Heartbeats;

/**
 * The scheduled `health:selftest` (every minute) ran within the threshold
 * (default 180 s). If the scheduler is not firing this ages out and `ready`
 * drops (contract §2, blueprint §12.2).
 */
final class SchedulerSelftestCheck extends HeartbeatCheck
{
    public function name(): string
    {
        return 'scheduler_selftest';
    }

    public function dependsOn(): array
    {
        return ['database_migrations'];
    }

    protected function heartbeat(): string
    {
        return Heartbeats::SCHEDULER;
    }

    protected function okDetail(int $ageSeconds): string
    {
        $interval = (int) config('health.intervals.scheduler_selftest');

        return "health:selftest ran {$ageSeconds}s ago (interval {$interval}s)";
    }

    protected function staleDetail(int $ageSeconds, int $threshold): string
    {
        return "health:selftest last ran {$ageSeconds}s ago (threshold {$threshold}s); schedule:work not running";
    }

    protected function missingDetail(): string
    {
        return 'health:selftest has not run; schedule:work not running';
    }
}
