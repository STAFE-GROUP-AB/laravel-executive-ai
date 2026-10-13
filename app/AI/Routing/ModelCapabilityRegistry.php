<?php

declare(strict_types=1);

namespace App\AI\Routing;

use App\AI\Providers\CapabilitySource;
use App\AI\Providers\ModelCapabilities;
use App\AI\Providers\ModelCapability;
use App\AI\Providers\ProviderKind;
use DateTimeImmutable;
use Illuminate\Contracts\Config\Repository;
use JsonException;
use RuntimeException;

/**
 * ADR-004 D3: per-model capabilities are **ours**. `laravel/ai` 1.2.0 knows only
 * whether a *provider* implements `TextProvider`, `EmbeddingProvider` and the
 * like — `AiManager::ensureProviderSupports()` throws
 * `LogicException('Provider [...] does not support {capability}.')` and there is
 * no per-model data anywhere in the package — so fail-closed routing is
 * impossible without this registry.
 *
 * Precedence, in D3's order and no other:
 *
 *   1. `resources/ai/models.json`, hand-maintained, one entry per
 *      `driver/model-id` with a `source_url` and `verified_at`.
 *   2. A runtime probe overlay for local models, which fills fields the registry
 *      left `null` and **never overrides a registry `false`**.
 *   3. The owner's `user_override`, which may assert a capability the registry
 *      does not know, marked unverified in the UI, and likewise never
 *      contradicts a registry `false`.
 *
 * A model no source knows is `ModelCapabilities::unknown()`: text and streaming
 * only, 4096 context, everything else `false`. The router refuses the job; it
 * never degrades it.
 */
final class ModelCapabilityRegistry
{
    /** Every capability a registry entry may list, keyed as in the artefact. */
    public const array ENTRY_CAPABILITIES = ['text', 'streaming', 'tools', 'structured_output', 'vision', 'embeddings'];

    /**
     * The decoded artefact, shared per path for the life of the process.
     *
     * The registry file lives inside the immutable versioned runtime (ADR-001
     * D4) and cannot change while the process runs, so decoding it once is
     * correct rather than merely faster — and a run that builds an adapter per
     * provider would otherwise re-read and re-decode the same bytes each time.
     *
     * @var array<string, array<string, array<string, mixed>>>
     */
    private static array $decoded = [];

    /** @var array<string, array<string, mixed>>|null */
    private ?array $entries = null;

    /** @var array<string, ProbedCapabilities> */
    private array $probes = [];

    /** @var array<string, CapabilityOverride> */
    private array $overrides = [];

    public function __construct(
        private readonly Repository $config,
        private readonly ?string $path = null,
    ) {}

    /**
     * Overlay what a local runtime reported about an installed model.
     */
    public function overlayProbe(ProbedCapabilities $probe): void
    {
        $this->probes[$probe->key()] = $probe;
    }

    /**
     * Record the owner's assertion about a model (Settings → Local Models).
     */
    public function overrideWith(CapabilityOverride $override): void
    {
        $this->overrides[$override->key()] = $override;
    }

    /**
     * True when the artefact marks this entry as needing a maintainer's review:
     * the capabilities it carries are not evidence yet, and the UI says so.
     */
    public function reviewRequired(string $driver, string $modelId): bool
    {
        $entry = $this->entry($driver, $modelId);

        return $entry !== null && ($entry['review_required'] ?? false) === true;
    }

    /**
     * The model ids the artefact recommends for a driver, in the order it lists
     * them. A recommendation is **never** a preselection: §12.3.5 and
     * ai-state.md §4 require the choice to start empty, so callers may show
     * these and may not pick one.
     *
     * @return list<string>
     */
    public function recommendedFor(string $driver): array
    {
        $recommended = [];

        foreach ($this->all() as $key => $entry) {
            if (str_starts_with($key, $driver.'/') && ($entry['recommended'] ?? false) === true) {
                $recommended[] = substr($key, strlen($driver) + 1);
            }
        }

        return $recommended;
    }

    public function knows(string $driver, string $modelId): bool
    {
        return $this->entry($driver, $modelId) !== null;
    }

    /**
     * Resolve the capabilities of one model on one driver.
     *
     * `$kind` is what decides pricing, and it is the provider's kind rather than
     * the driver's name on purpose: `openai-compatible` serves both LM Studio on
     * the owner's own Mac, which costs nothing to run, and a paid remote gateway,
     * which does. Keying "free" off the driver would quietly bill a hosted
     * endpoint at zero and make D7's budget meaningless.
     */
    public function resolve(string $driver, string $modelId, ?ProviderKind $kind = null): ModelCapabilities
    {
        $key = "{$driver}/{$modelId}";
        $entry = $this->entry($driver, $modelId);
        $probe = $this->probes[$key] ?? null;
        $override = $this->overrides[$key] ?? null;

        if ($entry === null && $probe === null && $override === null) {
            return $this->priced(ModelCapabilities::unknown($modelId), $kind);
        }

        $unknown = ModelCapabilities::unknown($modelId);
        $can = fn (ModelCapability $capability): bool => $this->resolveCapability($capability, $entry, $probe, $override, $unknown);

        return new ModelCapabilities(
            modelId: $modelId,
            text: $can(ModelCapability::Text),
            streaming: $can(ModelCapability::Streaming),
            tools: $can(ModelCapability::Tools),
            structuredOutput: $can(ModelCapability::StructuredOutput),
            vision: $can(ModelCapability::Vision),
            embeddings: $can(ModelCapability::Embeddings),
            embeddingDimensions: $this->resolveInt('embedding_dimensions', $entry, $probe?->embeddingDimensions, $override?->embeddingDimensions),
            contextWindow: $this->resolveInt('context_window', $entry, $probe?->contextWindow, $override?->contextWindow) ?? $unknown->contextWindow,
            maxOutputTokens: $this->resolveInt('max_output_tokens', $entry, $probe?->maxOutputTokens, $override?->maxOutputTokens),
            inputPricePerMTok: $this->resolvePrice('input_price_per_mtok_eur', $entry, $kind),
            outputPricePerMTok: $this->resolvePrice('output_price_per_mtok_eur', $entry, $kind),
            maxConcurrency: $this->resolveInt('max_concurrency', $entry, null, null) ?? $unknown->maxConcurrency,
            source: $this->resolveSource($entry, $probe, $override),
            verifiedAt: $this->resolveVerifiedAt($entry),
        );
    }

    /**
     * D3's precedence for one capability. A registry entry that says `false`
     * wins over both a probe and an override; a registry entry that is silent
     * lets the probe, then the override, speak; with nothing said at all the
     * fail-closed default applies.
     *
     * @param  array<string, mixed>|null  $entry
     */
    private function resolveCapability(
        ModelCapability $capability,
        ?array $entry,
        ?ProbedCapabilities $probe,
        ?CapabilityOverride $override,
        ModelCapabilities $unknown,
    ): bool {
        $declared = $entry === null ? null : ($entry['capabilities'] ?? null);
        $stated = is_array($declared) ? ($declared[$capability->value] ?? null) : null;

        if ($stated === false) {
            return false;
        }

        if ($stated === true) {
            return true;
        }

        if ($probe?->reports($capability) === true) {
            return true;
        }

        if ($override?->asserts($capability) === true) {
            return true;
        }

        return $unknown->supports($capability);
    }

    /**
     * @param  array<string, mixed>|null  $entry
     */
    private function resolveInt(string $field, ?array $entry, ?int $probed, ?int $overridden): ?int
    {
        $stated = $entry === null ? null : ($entry[$field] ?? null);

        if (is_int($stated)) {
            return $stated;
        }

        return $probed ?? $overridden;
    }

    /**
     * Prices are EUR per million tokens. A model on the owner's own machine
     * costs nothing to run, so it is `0.0` by construction; a remote model whose
     * EUR price the artefact does not carry is `null` ("unknown"), never a guess
     * — the vendor publishes USD and no conversion policy is recorded yet (see
     * the artefact's `currency` block and ADR-004 D7, which owns the budget).
     *
     * @param  array<string, mixed>|null  $entry
     */
    private function resolvePrice(string $field, ?array $entry, ?ProviderKind $kind): ?float
    {
        if ($kind === ProviderKind::Local) {
            return 0.0;
        }

        $stated = $entry === null ? null : ($entry[$field] ?? null);

        return is_int($stated) || is_float($stated) ? (float) $stated : null;
    }

    /**
     * A model nobody knows still records its cost honestly: zero on the owner's
     * hardware, unknown anywhere else.
     */
    private function priced(ModelCapabilities $capabilities, ?ProviderKind $kind): ModelCapabilities
    {
        if ($kind !== ProviderKind::Local) {
            return $capabilities;
        }

        return new ModelCapabilities(
            modelId: $capabilities->modelId,
            text: $capabilities->text,
            streaming: $capabilities->streaming,
            tools: $capabilities->tools,
            structuredOutput: $capabilities->structuredOutput,
            vision: $capabilities->vision,
            embeddings: $capabilities->embeddings,
            embeddingDimensions: $capabilities->embeddingDimensions,
            contextWindow: $capabilities->contextWindow,
            maxOutputTokens: $capabilities->maxOutputTokens,
            inputPricePerMTok: 0.0,
            outputPricePerMTok: 0.0,
            maxConcurrency: $capabilities->maxConcurrency,
            source: $capabilities->source,
            verifiedAt: $capabilities->verifiedAt,
        );
    }

    /**
     * @param  array<string, mixed>|null  $entry
     */
    private function resolveSource(?array $entry, ?ProbedCapabilities $probe, ?CapabilityOverride $override): CapabilitySource
    {
        return match (true) {
            $entry !== null => CapabilitySource::Registry,
            $probe !== null => CapabilitySource::Probe,
            $override !== null => CapabilitySource::UserOverride,
            default => CapabilitySource::Unknown,
        };
    }

    /**
     * @param  array<string, mixed>|null  $entry
     */
    private function resolveVerifiedAt(?array $entry): ?DateTimeImmutable
    {
        // An entry still under review carries capabilities that nobody has
        // verified yet, so it reports no verification date at all.
        if ($entry === null || ($entry['review_required'] ?? false) === true) {
            return null;
        }

        $verifiedAt = $entry['verified_at'] ?? null;

        return is_string($verifiedAt) ? new DateTimeImmutable($verifiedAt) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function entry(string $driver, string $modelId): ?array
    {
        $entry = $this->all()["{$driver}/{$modelId}"] ?? null;

        return is_array($entry) ? $entry : null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function all(): array
    {
        if ($this->entries !== null) {
            return $this->entries;
        }

        $path = $this->path ?? (string) $this->config->get('lea.ai.models_registry');

        if (isset(self::$decoded[$path])) {
            return $this->entries = self::$decoded[$path];
        }

        if (! is_file($path)) {
            throw new RuntimeException("The model capability registry is missing at [{$path}] (ADR-004 D3).");
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The model capability registry is not valid JSON.', previous: $exception);
        }

        $models = is_array($decoded) ? ($decoded['models'] ?? null) : null;

        if (! is_array($models)) {
            throw new RuntimeException('The model capability registry has no `models` object (ADR-004 D3).');
        }

        /** @var array<string, array<string, mixed>> $models */
        return $this->entries = self::$decoded[$path] = $models;
    }
}
