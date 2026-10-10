<?php

declare(strict_types=1);

namespace App\AI\Providers;

use Illuminate\Contracts\Container\Container;

/**
 * The providers SELECT_AI and the PWA may offer (blueprint §1.1: "only options a
 * real adapter supports"; §12.3 screen 5: nothing preselected).
 *
 * The catalogue below mirrors packaging/fixtures/ai-providers/ai-providers.json,
 * the one list both this core and the Mac installer (`AiProviderCatalog`) read;
 * tests/Unit/AI/SupportedProvidersTest.php pins the equality so neither side can
 * drift. A provider is *supported* only when its catalogue row says an adapter is
 * available AND an AiProviderAdapter is actually bound under adapterBinding(id).
 * With no adapter bound (Sprint 1) supported() is empty and the UI says so.
 */
final class SupportedProviders
{
    public const string FIXTURE = 'packaging/fixtures/ai-providers/ai-providers.json';

    /**
     * @var list<array{
     *     id: string,
     *     display_name: string,
     *     kind: string,
     *     driver: string,
     *     adapter_available: bool
     * }>
     */
    private const array CATALOGUE = [
        ['id' => 'ollama', 'display_name' => 'Ollama', 'kind' => 'local', 'driver' => 'ollama', 'adapter_available' => false],
        ['id' => 'lmstudio', 'display_name' => 'LM Studio', 'kind' => 'local', 'driver' => 'openai-compatible', 'adapter_available' => false],
        ['id' => 'openai', 'display_name' => 'OpenAI', 'kind' => 'remote', 'driver' => 'openai', 'adapter_available' => false],
        ['id' => 'anthropic', 'display_name' => 'Anthropic', 'kind' => 'remote', 'driver' => 'anthropic', 'adapter_available' => false],
        ['id' => 'custom', 'display_name' => 'Other OpenAI-compatible endpoint', 'kind' => 'remote', 'driver' => 'openai-compatible', 'adapter_available' => false],
    ];

    /** @param list<ProviderDescriptor>|null $catalogue Overrides the frozen catalogue; tests only. */
    public function __construct(
        private readonly Container $container,
        private readonly ?array $catalogue = null,
    ) {}

    /** @return list<ProviderDescriptor> */
    public static function catalogue(): array
    {
        return array_map(ProviderDescriptor::fromArray(...), self::CATALOGUE);
    }

    public static function find(string $id): ?ProviderDescriptor
    {
        foreach (self::catalogue() as $provider) {
            if ($provider->id === $id) {
                return $provider;
            }
        }

        return null;
    }

    /**
     * Container abstract an adapter for `$id` must be bound under to count as real.
     */
    public static function adapterBinding(string $id): string
    {
        return AiProviderAdapter::class.'@'.$id;
    }

    /** @return list<ProviderDescriptor> */
    public function all(): array
    {
        return $this->catalogue ?? self::catalogue();
    }

    /** @return list<ProviderDescriptor> */
    public function supported(): array
    {
        return array_values(array_filter(
            $this->all(),
            fn (ProviderDescriptor $provider): bool => $provider->adapterAvailable
                && $this->container->bound(self::adapterBinding($provider->id)),
        ));
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_map(fn (ProviderDescriptor $provider): string => $provider->id, $this->supported());
    }

    public function isSupported(string $id): bool
    {
        return in_array($id, $this->ids(), true);
    }
}
