<?php

declare(strict_types=1);

namespace App\AI\Providers\LaravelAi;

use App\AI\Providers\AdapterFactory;
use App\AI\Providers\AiProviderAdapter;
use App\AI\Providers\ProviderConnection;
use App\AI\Routing\ModelCapabilityRegistry;
use App\Security\Secrets\SecretStore;
use App\Security\Secrets\SecretUnavailableException;
use Laravel\Ai\Ai;
use Laravel\Ai\Enums\Lab;

/**
 * Registers a configured provider with the SDK and hands back an adapter for it
 * (ADR-004 D2 "provider construction": `Ai::build([...])` with the key read
 * from the Keychain).
 *
 * Why a build per connection rather than `config/ai.php`: the key must come from
 * the Keychain at call time and must never sit in a config file or `.env`
 * (ADR-003), and `config/ai.php`'s own provider entries all read `env()`. An
 * on-demand provider is also registered under **our** name, not the driver's,
 * so a forgotten argument cannot resolve to a configured brand — `ai.default`
 * is the undefined `'unconfigured'` for the same reason (D1).
 *
 * `Ai::build()` refuses a name that collides with a `Lab` case or a configured
 * provider, so the name is prefixed.
 */
final readonly class LaravelAiAdapterFactory implements AdapterFactory
{
    /**
     * Prefix for on-demand provider names. `lea.` cannot collide with a `Lab`
     * value or a `config('ai.providers.*')` key.
     */
    public const string NAME_PREFIX = 'lea.';

    public function __construct(
        private SecretStore $secrets,
        private ModelCapabilityRegistry $capabilities,
        private SdkMessageMapper $messages,
        private UsageMapper $usage,
        private ErrorMapper $errors,
    ) {}

    public function make(ProviderConnection $connection): AiProviderAdapter
    {
        $name = self::NAME_PREFIX.$connection->providerId;

        Ai::build($this->configFor($connection, $name));

        return new LaravelAiAdapter(
            connection: $connection,
            providerName: $name,
            capabilities: $this->capabilities,
            messages: $this->messages,
            usage: $this->usage,
            errors: $this->errors,
        );
    }

    /**
     * The SDK provider configuration. `models` is deliberately absent: leaving
     * it unset means `OllamaProvider::defaultTextModel()` would have to supply a
     * model, and our adapter never lets it — every request names its own (D1,
     * matrix row 12). An `openai-compatible` provider without a configured
     * default model throws if anyone ever omits one, which is the behaviour we
     * want.
     *
     * @return array<string, mixed>
     */
    private function configFor(ProviderConnection $connection, string $name): array
    {
        $config = [
            'name' => $name,
            'driver' => Lab::from($connection->driver)->value,
            'key' => $this->keyFor($connection),
        ];

        if ($connection->baseUrl !== null) {
            $config['url'] = $connection->baseUrl;
        }

        if ($connection->headers !== []) {
            $config['headers'] = $connection->headers;
        }

        return $config;
    }

    /**
     * The API key, read from the Keychain through the Supervisor's broker at
     * build time and kept in a local, never in a property (ADR-003 D1: the
     * `se.stafe.executive-ai.ai-provider` item holds an `api_key` envelope,
     * `{"v":1,"kind":"api_key","key":"…"}`).
     *
     * A local runtime has no key. A missing, locked or malformed item yields an
     * empty key rather than an exception: the provider is still built and the
     * *provider* reports the authentication failure, which is a state the UI
     * already renders ("AI error", reconfigure) — whereas throwing here would
     * make a locked Keychain look like a broken install.
     */
    private function keyFor(ProviderConnection $connection): string
    {
        if ($connection->keyRef === null) {
            return '';
        }

        try {
            $envelope = $this->secrets->get($connection->keyRef)->envelope();
        } catch (SecretUnavailableException) {
            return '';
        }

        if (($envelope['kind'] ?? null) !== 'api_key') {
            return '';
        }

        $key = $envelope['key'] ?? $envelope['api_key'] ?? null;

        return is_string($key) ? $key : '';
    }
}
