<?php

declare(strict_types=1);

namespace App\Support\Health\Checks;

use App\Support\Health\Heartbeats;

/**
 * The database-queue worker wrote a heartbeat within the threshold (default
 * 90 s). QueueWorkerHeartbeat writes it from the worker loop.
 */
final class QueueWorkerCheck extends HeartbeatCheck
{
    public function name(): string
    {
        return 'queue_worker';
    }

    public function dependsOn(): array
    {
        return ['database_migrations'];
    }

    protected function heartbeat(): string
    {
        return Heartbeats::QUEUE_WORKER;
    }

    protected function okDetail(int $ageSeconds): string
    {
        return "worker heartbeat {$ageSeconds}s ago";
    }

    protected function staleDetail(int $ageSeconds, int $threshold): string
    {
        return "worker heartbeat {$ageSeconds}s ago (threshold {$threshold}s); queue:work not running";
    }

    protected function missingDetail(): string
    {
        return 'no worker heartbeat recorded; queue:work not running';
    }
}
