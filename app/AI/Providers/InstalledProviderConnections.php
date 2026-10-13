<?php

declare(strict_types=1);

namespace App\AI\Providers;

use App\Security\Secrets\SecretRef;
use Illuminate\Contracts\Config\Repository;

/**
 * The provider the owner chose in SELECT_AI, read from the install
 * configuration (`lea.ai.provider` plus `lea.ai.connection`) rather than from a
 * table, because `ai_providers` is S2-02 (#85).
 *
 * One selected provider is all §12.3 screen 5 can produce, so this returns at
 * most one connection. Its key always comes from the Keychain through
 * `SecretRef::AI_PROVIDER`; a key in config or `.env` is never read (ADR-003).
 */
final readonly class InstalledProviderConnections implements ProviderConnections
{
    public function __construct(private Repository $config) {}

    /** @return list<ProviderConnection> */
    public function all(): array
    {
        $connection = $this->selected();

        return $connection === null ? [] : [$connection];
    }

    public function find(string $providerId): ?ProviderConnection
    {
        $connection = $this->selected();

        return $connection?->providerId === $providerId ? $connection : null;
    }

    private function selected(): ?ProviderConnection
    {
        $id = $this->config->get('lea.ai.provider');

        if (! is_string($id) || $id === '') {
            return null;
        }

        $descriptor = SupportedProviders::find($id);

        if ($descriptor === null) {
            return null;
        }

        $baseUrl = $this->config->get("lea.ai.connection.{$id}.url");

        return new ProviderConnection(
            providerId: $descriptor->id,
            driver: $descriptor->driver,
            kind: $descriptor->kind,
            baseUrl: is_string($baseUrl) && $baseUrl !== '' ? $baseUrl : null,
            keyRef: $descriptor->kind === ProviderKind::Remote ? new SecretRef(SecretRef::AI_PROVIDER, $descriptor->id) : null,
            regionEvidence: $descriptor->kind === ProviderKind::Local ? RegionEvidence::localMachine() : null,
        );
    }
}
