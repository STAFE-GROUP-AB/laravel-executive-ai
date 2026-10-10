<?php

declare(strict_types=1);

namespace App\Support\Health;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;

/**
 * The cached heartbeats the ready checks read (health contract §2 "evaluated
 * cheaply"). Writers:
 *
 *  - MENUBAR          POST /ipc/v1/heartbeat (S1-08, IPC contract §6.5) — only an
 *                     authenticated `menubar`-scoped request may write it.
 *  - QUEUE_WORKER     QueueWorkerHeartbeat on every worker loop iteration.
 *  - QUEUE_SELFTEST   QueueSelftestJob when the worker processes it.
 *  - SCHEDULER        `health:selftest`, scheduled every minute.
 *  - LOGIN_SELFTEST   LoginSelfTest, run by `health:selftest`.
 *  - BRIEF_GENERATION_ERROR  the brief generator on a failed scheduled run.
 *  - CONNECTIVITY     the sync layer (Sprint 3) as `online|degraded|offline`.
 *
 * Only timestamps and small non-secret attributes are stored; never a token.
 */
final class Heartbeats
{
    public const string MENUBAR = 'menubar';

    public const string QUEUE_WORKER = 'queue_worker';

    public const string QUEUE_SELFTEST = 'queue_selftest';

    public const string SCHEDULER = 'scheduler';

    public const string LOGIN_SELFTEST = 'login_selftest';

    public const string BRIEF_GENERATION_ERROR = 'brief_generation_error';

    public const string CONNECTIVITY = 'connectivity';

    private const string PREFIX = 'health:heartbeat:';

    public function __construct(private readonly Repository $cache) {}

    public static function key(string $name): string
    {
        return self::PREFIX.$name;
    }

    /** @param array<string, scalar|null> $attributes */
    public function record(string $name, array $attributes = [], ?CarbonImmutable $at = null): void
    {
        $at ??= CarbonImmutable::now('UTC');

        $this->cache->put(self::key($name), ['at' => $at->toRfc3339String(), ...$attributes], $at->addDay());
    }

    public function forget(string $name): void
    {
        $this->cache->forget(self::key($name));
    }

    /** @return array<string, mixed>|null */
    public function read(string $name): ?array
    {
        $value = $this->cache->get(self::key($name));

        return is_array($value) && isset($value['at']) ? $value : null;
    }

    public function lastSeenAt(string $name): ?CarbonImmutable
    {
        $value = $this->read($name);

        return $value === null ? null : CarbonImmutable::parse((string) $value['at'])->utc();
    }

    public function ageSeconds(string $name, ?CarbonImmutable $now = null): ?int
    {
        $lastSeenAt = $this->lastSeenAt($name);

        if ($lastSeenAt === null) {
            return null;
        }

        return max(0, (int) $lastSeenAt->diffInSeconds($now ?? CarbonImmutable::now('UTC'), absolute: true));
    }
}
