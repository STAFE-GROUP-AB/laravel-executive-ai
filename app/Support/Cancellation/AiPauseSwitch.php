<?php

declare(strict_types=1);

namespace App\Support\Cancellation;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;

/**
 * The global cooperative cancellation token of ADR-004 D7: "Pause AI actions
 * in the menu bar flips a global token". The state lives in the cache so the
 * HTTP core, the queue worker and the scheduler see the same switch; the
 * token handed to a run (`token()`) reads it between steps and stream chunks.
 * Pause and resume are idempotent (IPC contract §6.4).
 */
final class AiPauseSwitch
{
    public const string CACHE_KEY = 'ai:pause';

    public function __construct(private readonly Repository $cache) {}

    public function status(): AiPauseStatus
    {
        $stored = $this->cache->get(self::CACHE_KEY);

        if (! is_array($stored) || ! isset($stored['state'], $stored['changed_at'])) {
            return new AiPauseStatus(false, CarbonImmutable::now('UTC'), null, null);
        }

        return new AiPauseStatus(
            $stored['state'] === 'paused',
            CarbonImmutable::parse((string) $stored['changed_at'])->utc(),
            isset($stored['paused_since']) ? CarbonImmutable::parse((string) $stored['paused_since'])->utc() : null,
            is_string($stored['reason'] ?? null) ? $stored['reason'] : null,
        );
    }

    public function isPaused(): bool
    {
        return $this->status()->paused;
    }

    public function pause(?string $reason = null, ?CarbonImmutable $now = null): AiPauseStatus
    {
        $current = $this->status();

        if ($current->paused) {
            return $current;
        }

        $now ??= CarbonImmutable::now('UTC');

        $this->cache->forever(self::CACHE_KEY, [
            'state' => 'paused',
            'changed_at' => $now->toRfc3339String(),
            'paused_since' => $now->toRfc3339String(),
            'reason' => $reason,
        ]);

        return new AiPauseStatus(true, $now, $now, $reason, changed: true);
    }

    public function resume(?CarbonImmutable $now = null): AiPauseStatus
    {
        $current = $this->status();

        if (! $current->paused) {
            return $current;
        }

        $now ??= CarbonImmutable::now('UTC');

        $this->cache->forever(self::CACHE_KEY, [
            'state' => 'running',
            'changed_at' => $now->toRfc3339String(),
            'paused_since' => null,
            'reason' => null,
        ]);

        return new AiPauseStatus(false, $now, null, null, changed: true);
    }

    /** The token a model run observes; cancelled while the switch is paused. */
    public function token(): CancellationToken
    {
        return new AiPauseToken($this);
    }
}
