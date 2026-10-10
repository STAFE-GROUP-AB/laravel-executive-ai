<?php

declare(strict_types=1);

namespace App\Security\Ipc;

use Illuminate\Contracts\Cache\Repository;

/**
 * §4.3 replay protection: accepted nonces live in the Laravel cache for
 * `ipc.nonce_window_seconds` (120 s, four times the skew window) under
 * `ipc:nonce:<session_id>:<nonce>`, so re-pairing can never resurrect a
 * nonce from another session. `remember()` is an atomic add; a nonce that
 * is already present is a replay.
 *
 * The per-session population is bounded by the §9 rate limits (at most
 * 120 requests a minute, so at most 240 live entries) rather than by an
 * explicit LRU; the cache store's TTL eviction does the rest.
 */
final class IpcNonceCache
{
    public function __construct(private readonly Repository $cache) {}

    public static function key(string $sessionId, string $nonce): string
    {
        return "ipc:nonce:{$sessionId}:{$nonce}";
    }

    public function wasSeen(string $sessionId, string $nonce): bool
    {
        return $this->cache->has(self::key($sessionId, $nonce));
    }

    /** True when the nonce was fresh and is now remembered; false when it was already present. */
    public function remember(string $sessionId, string $nonce): bool
    {
        return $this->cache->add(self::key($sessionId, $nonce), 1, (int) config('ipc.nonce_window_seconds'));
    }
}
