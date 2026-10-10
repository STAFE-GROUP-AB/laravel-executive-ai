<?php

declare(strict_types=1);

namespace App\Support\Health;

use Illuminate\Queue\Events\Looping;

/**
 * Writes the QUEUE_WORKER heartbeat from the worker loop (health contract §2:
 * "written by the worker loop every 30 s"). The loop fires Looping every
 * iteration, including idle sleeps, so writes are throttled to the
 * configured interval to keep idle I/O low (§14).
 */
final class QueueWorkerHeartbeat
{
    private static ?float $lastWrittenAt = null;

    public function __construct(private readonly Heartbeats $heartbeats) {}

    public function handle(Looping $event): void
    {
        $interval = (int) config('health.intervals.queue_worker');
        $now = microtime(true);

        if (self::$lastWrittenAt !== null && ($now - self::$lastWrittenAt) < $interval) {
            return;
        }

        $this->heartbeats->record(Heartbeats::QUEUE_WORKER, [
            'connection' => $event->connectionName,
            'queue' => $event->queue,
        ]);

        self::$lastWrittenAt = $now;
    }

    public static function resetThrottle(): void
    {
        self::$lastWrittenAt = null;
    }
}
