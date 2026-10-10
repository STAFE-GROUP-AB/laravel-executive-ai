<?php

declare(strict_types=1);

namespace App\Support\Health;

/**
 * Internet reachability as last measured by the sync layer, cached (health
 * contract). Until the Sprint 3 sync layer records a measurement the core
 * has no enabled external provider and reports `online`; the endpoint never
 * probes the network itself.
 */
final class Connectivity
{
    public const string ONLINE = 'online';

    public const string DEGRADED = 'degraded';

    public const string OFFLINE = 'offline';

    public function __construct(private readonly Heartbeats $heartbeats) {}

    public function record(string $state): void
    {
        if (! in_array($state, [self::ONLINE, self::DEGRADED, self::OFFLINE], true)) {
            throw new \InvalidArgumentException("Unknown connectivity state `{$state}`");
        }

        $this->heartbeats->record(Heartbeats::CONNECTIVITY, ['state' => $state]);
    }

    public function current(): string
    {
        $measured = $this->heartbeats->read(Heartbeats::CONNECTIVITY);
        $state = $measured['state'] ?? null;

        return in_array($state, [self::ONLINE, self::DEGRADED, self::OFFLINE], true) ? $state : self::ONLINE;
    }
}
