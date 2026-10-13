<?php

declare(strict_types=1);

namespace App\AI\Providers;

/**
 * Where the configured providers come from. The S2-02 (#85) `ai_providers`
 * table will back this with a repository; until then the installer's selection
 * is read from config (`InstalledProviderConnections`) and tests use an
 * in-memory list. The registry and the router only ever see this interface, so
 * neither needs the table to exist.
 */
interface ProviderConnections
{
    /** @return list<ProviderConnection> */
    public function all(): array;

    public function find(string $providerId): ?ProviderConnection;
}
