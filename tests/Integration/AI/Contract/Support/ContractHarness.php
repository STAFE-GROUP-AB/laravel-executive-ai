<?php

declare(strict_types=1);

namespace Tests\Integration\AI\Contract\Support;

use App\AI\Providers\AiProviderAdapter;
use App\AI\Providers\Dto\AiMessage;
use App\AI\Providers\Dto\AiMessageRole;
use App\AI\Providers\Dto\AiRequest;
use App\AI\Providers\InMemoryProviderConnections;
use App\AI\Providers\LaravelAi\ErrorMapper;
use App\AI\Providers\LaravelAi\LaravelAiAdapterFactory;
use App\AI\Providers\LaravelAi\SdkAgentBridge;
use App\AI\Providers\LaravelAi\SdkMessageMapper;
use App\AI\Providers\LaravelAi\StructuredSdkAgentBridge;
use App\AI\Providers\LaravelAi\UsageMapper;
use App\AI\Providers\ModelCapability;
use App\AI\Providers\ProviderConnection;
use App\AI\Providers\ProviderKind;
use App\AI\Providers\ProviderRegistry;
use App\AI\Providers\RegionEvidence;
use App\AI\Routing\CapabilityOverride;
use App\AI\Routing\ModelCapabilityRegistry;
use App\AI\Routing\ModelRouter;
use App\AI\Routing\PrivacyMode;
use App\Security\Secrets\ArraySecretStore;
use App\Security\Secrets\Redactor;
use App\Security\Secrets\SecretRef;
use App\Security\Secrets\SecretStore;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Ai;
use Laravel\Ai\Gateway\FakeTextGateway;

/**
 * The world the twelve-row contract matrix runs in (ADR-004 D2).
 *
 * **Nothing here touches a network.** Every row runs against the SDK's own
 * `FakeTextGateway`, and `Http::preventStrayRequests()` is armed on top so a
 * request that escaped the fake would fail the test rather than reach a vendor.
 * There is no API key in this repository, and the matrix's other half — the same
 * rows against real Ollama, real LM Studio and a test key — is a Mac-lab run
 * recorded in ADR-004's Evidence section. CI never claims to have done it.
 *
 * It is also the one place that knows which drivers ship, so adding a driver
 * means adding a row to `drivers()` and the whole matrix runs against it.
 */
final class ContractHarness
{
    public const string TRACE_ID = '01M4AI0000000000000CONTRACT';

    /** A key-shaped string that is obviously not a key; the gateway never sees it. */
    public const string FAKE_KEY_ENVELOPE = '{"v":1,"kind":"api_key","key":"sk-FAKE-00000000-contract-matrix"}';

    /**
     * The adapters S2-13 ships, one per catalogue row (ADR-004 D6 v1 list). Each
     * must pass every row of the matrix.
     *
     * @return array<string, array{providerId: string, driver: string, kind: ProviderKind, baseUrl: ?string, local: bool}>
     */
    public static function drivers(): array
    {
        return [
            'ollama' => ['providerId' => 'ollama', 'driver' => 'ollama', 'kind' => ProviderKind::Local, 'baseUrl' => 'http://127.0.0.1:11434', 'local' => true],
            'lmstudio' => ['providerId' => 'lmstudio', 'driver' => 'openai-compatible', 'kind' => ProviderKind::Local, 'baseUrl' => 'http://127.0.0.1:1234/v1', 'local' => true],
            'openai' => ['providerId' => 'openai', 'driver' => 'openai', 'kind' => ProviderKind::Remote, 'baseUrl' => null, 'local' => false],
            'anthropic' => ['providerId' => 'anthropic', 'driver' => 'anthropic', 'kind' => ProviderKind::Remote, 'baseUrl' => null, 'local' => false],
            'custom' => ['providerId' => 'custom', 'driver' => 'openai-compatible', 'kind' => ProviderKind::Remote, 'baseUrl' => 'https://models.example.test/v1', 'local' => false],
        ];
    }

    /** @return list<string> */
    public static function driverNames(): array
    {
        return array_keys(self::drivers());
    }

    /**
     * The model id each driver is exercised with.
     *
     * The two external ones are the registry's **recommended** models
     * (`gpt-5-mini`, `claude-opus-4-1`) — used here because they are the entries
     * whose capabilities the registry carries, which is not the same thing as
     * preselecting them anywhere in the product.
     *
     * The `openai-compatible` drivers name a model no registry can know, which
     * is the truth about LM Studio and a custom gateway: the owner brings the
     * model. Under D3 such a model is unknown — text and streaming only — so the
     * tool and structured-output rows are reached through the owner's own
     * `user_override`, the third and last level of the precedence chain. That is
     * the documented mechanism for asserting a capability the registry does not
     * know, and it carries the "unverified — may fail" marker in the UI.
     */
    public static function modelFor(string $name): string
    {
        return match ($name) {
            'openai' => 'gpt-5-mini',
            'anthropic' => 'claude-opus-4-1',
            'lmstudio', 'custom' => 'byo-model',
            default => 'llama3.2:3b',
        };
    }

    public static function connection(string $name): ProviderConnection
    {
        $driver = self::drivers()[$name];

        return new ProviderConnection(
            providerId: $driver['providerId'],
            driver: $driver['driver'],
            kind: $driver['kind'],
            baseUrl: $driver['baseUrl'],
            keyRef: $driver['local'] ? null : new SecretRef(SecretRef::AI_PROVIDER, $driver['providerId']),
            modelIds: [self::modelFor($name)],
            regionEvidence: $driver['local'] ? RegionEvidence::localMachine() : null,
        );
    }

    /**
     * An adapter for one driver, with the SDK's fake gateway scripted by
     * `$responses` and stray HTTP forbidden.
     *
     * The fake is attached per agent class, which is how `Ai::fakeAgent()`
     * works, so both bridge classes are faked: the adapter picks the structured
     * one when a request carries a schema.
     *
     * @param  array<int, mixed>|\Closure  $responses  as `Agent::fake()` takes them
     */
    public static function adapter(string $name, array|\Closure $responses = [], ?PrivacyMode $mode = null): AiProviderAdapter
    {
        Http::preventStrayRequests();

        self::fakeGateways($responses);

        $connection = self::connection($name);
        $registry = new ProviderRegistry(
            connections: new InMemoryProviderConnections([$connection]),
            factory: self::factory(),
            router: self::router([$connection]),
        );

        return $registry->adapterFor($connection->providerId, $mode ?? PrivacyMode::Custom);
    }

    /**
     * @param  array<int, mixed>|\Closure  $responses
     * @return list<FakeTextGateway>
     */
    public static function fakeGateways(array|\Closure $responses = []): array
    {
        return [
            Ai::fakeAgent(SdkAgentBridge::class, $responses),
            Ai::fakeAgent(StructuredSdkAgentBridge::class, $responses),
        ];
    }

    public static function factory(): LaravelAiAdapterFactory
    {
        return new LaravelAiAdapterFactory(
            secrets: self::secrets(),
            capabilities: self::capabilities(),
            messages: new SdkMessageMapper,
            usage: new UsageMapper,
            errors: new ErrorMapper(app(Redactor::class)),
        );
    }

    /**
     * @param  list<ProviderConnection>  $connections
     */
    public static function router(array $connections): ModelRouter
    {
        return new ModelRouter(
            connections: new InMemoryProviderConnections($connections),
            capabilities: self::capabilities(),
        );
    }

    /**
     * The registry as the matrix sees it: the maintained artefact, plus the
     * owner's override for the bring-your-own model the `openai-compatible`
     * drivers serve. Nothing here overrides a registry `false` — the override
     * only speaks where the registry is silent, which is the asymmetry
     * `ModelCapabilityRegistryTest` pins.
     */
    public static function capabilities(): ModelCapabilityRegistry
    {
        $registry = new ModelCapabilityRegistry(app('config'), base_path('resources/ai/models.json'));

        $registry->overrideWith(new CapabilityOverride(
            driver: 'openai-compatible',
            modelId: 'byo-model',
            capabilities: [
                ModelCapability::Text,
                ModelCapability::Streaming,
                ModelCapability::Tools,
                ModelCapability::StructuredOutput,
            ],
            contextWindow: 32768,
            maxOutputTokens: 4096,
        ));

        return $registry;
    }

    /**
     * A secret store holding a fake key for every remote provider, so the
     * factory's Keychain read is exercised without a real credential existing
     * anywhere.
     */
    public static function secrets(): SecretStore
    {
        $items = [];

        foreach (self::drivers() as $driver) {
            if (! $driver['local']) {
                $items[SecretRef::AI_PROVIDER.'/'.$driver['providerId']] = self::FAKE_KEY_ENVELOPE;
            }
        }

        return new ArraySecretStore($items);
    }

    /**
     * A minimal valid request: one user turn, text only.
     *
     * @param  list<AiMessage>|null  $messages
     * @param  array<string, mixed>  $overrides
     */
    public static function request(string $name, ?array $messages = null, array $overrides = []): AiRequest
    {
        return new AiRequest(
            model: $overrides['model'] ?? self::modelFor($name),
            messages: $messages ?? [new AiMessage(AiMessageRole::User, 'Summarise today in one sentence.')],
            privacyMode: $overrides['privacyMode'] ?? PrivacyMode::Custom,
            traceId: $overrides['traceId'] ?? self::TRACE_ID,
            instructions: $overrides['instructions'] ?? 'You are the executive assistant.',
            tools: $overrides['tools'] ?? [],
            outputSchema: $overrides['outputSchema'] ?? null,
            maxOutputTokens: $overrides['maxOutputTokens'] ?? null,
            maxSteps: $overrides['maxSteps'] ?? null,
            timeoutSeconds: $overrides['timeoutSeconds'] ?? null,
            temperature: $overrides['temperature'] ?? null,
            topP: $overrides['topP'] ?? null,
        );
    }
}
