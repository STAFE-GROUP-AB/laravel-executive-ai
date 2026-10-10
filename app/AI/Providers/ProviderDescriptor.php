<?php

declare(strict_types=1);

namespace App\AI\Providers;

/**
 * One row of the provider catalogue (packaging/fixtures/ai-providers/ai-providers.json).
 * Presentation data only: no URL, no key, no model. `adapterAvailable` is the
 * honesty flag — true only when a real AiProviderAdapter for this provider is
 * bound in the container (SupportedProvidersTest refuses any other combination).
 */
final readonly class ProviderDescriptor
{
    public function __construct(
        public string $id,
        public string $displayName,
        public ProviderKind $kind,
        public string $driver,
        public bool $adapterAvailable,
    ) {}

    /**
     * @param array{
     *     id: string,
     *     display_name: string,
     *     kind: string,
     *     driver: string,
     *     adapter_available: bool
     * } $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            id: $row['id'],
            displayName: $row['display_name'],
            kind: ProviderKind::from($row['kind']),
            driver: $row['driver'],
            adapterAvailable: $row['adapter_available'],
        );
    }

    /**
     * @return array{
     *     id: string,
     *     display_name: string,
     *     kind: string,
     *     driver: string,
     *     adapter_available: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'display_name' => $this->displayName,
            'kind' => $this->kind->value,
            'driver' => $this->driver,
            'adapter_available' => $this->adapterAvailable,
        ];
    }
}
