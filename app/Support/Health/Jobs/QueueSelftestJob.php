<?php

declare(strict_types=1);

namespace App\Support\Health\Jobs;

use App\Support\Health\Heartbeats;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Scheduled every minute. When the worker processes it, the end-to-end
 * dispatch -> pop -> handle path is proven and the QUEUE_SELFTEST heartbeat
 * is recorded. Unique so a stalled worker cannot pile up copies.
 */
final class QueueSelftestJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 120;

    public int $tries = 1;

    public function handle(Heartbeats $heartbeats): void
    {
        $heartbeats->record(Heartbeats::QUEUE_SELFTEST);
    }
}
