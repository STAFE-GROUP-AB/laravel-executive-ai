<?php

declare(strict_types=1);

namespace App\AI\Providers;

/**
 * A fixed list of connections. Used by the contract-test matrix and by the
 * installer hand-off until the S2-02 (#85) `ai_providers` table lands.
 */
final readonly class InMemoryProviderConnections implements ProviderConnections
{
    /** @param list<ProviderConnection> $connections */
    public function __construct(private array $connections = []) {}

    /** @return list<ProviderConnection> */
    public function all(): array
    {
        return $this->connections;
    }

    public function find(string $providerId): ?ProviderConnection
    {
        foreach ($this->connections as $connection) {
            if ($connection->providerId === $providerId) {
                return $connection;
            }
        }

        return null;
    }
}
