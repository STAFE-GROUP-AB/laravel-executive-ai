<?php

declare(strict_types=1);

use App\AI\Providers\CapabilitySource;
use App\AI\Providers\Dto\AiErrorKind;
use App\AI\Providers\Exceptions\CapabilityUnavailableException;
use App\AI\Providers\Exceptions\ContextWindowExceededException;
use App\AI\Providers\Exceptions\EgressBlockedException;
use App\AI\Providers\Exceptions\RegionEvidenceMissingException;
use App\AI\Providers\InMemoryProviderConnections;
use App\AI\Providers\ModelCapability;
use App\AI\Providers\ProcessingRegion;
use App\AI\Providers\ProviderConnection;
use App\AI\Providers\ProviderKind;
use App\AI\Providers\RegionEvidence;
use App\AI\Providers\RegionMechanism;
use App\AI\Routing\CapabilityOverride;
use App\AI\Routing\JobRequirements;
use App\AI\Routing\ModelCapabilityRegistry;
use App\AI\Routing\ModelRouter;
use App\AI\Routing\PrivacyMode;
use App\AI\Routing\ProbedCapabilities;
use PHPUnit\Framework\Assert;

/*
 * ADR-004 D3: capability-aware routing that fails closed. The router filters the
 * models the owner configured by required capabilities, privacy-mode
 * eligibility and context size, and refuses when nothing is left — with the
 * reason that actually applies, never a reason that misdescribes what happened.
 *
 * The capability precedence (registry → probe → user override, and a probe never
 * overriding a registry `false`) is tested here too, because precedence is what
 * makes "fail closed" mean anything: a source that could talk the product into
 * a capability nobody verified would undo the whole chain.
 */

function routerRegistry(): ModelCapabilityRegistry
{
    return new ModelCapabilityRegistry(app('config'), base_path('resources/ai/models.json'));
}

function localOllama(string ...$models): ProviderConnection
{
    return new ProviderConnection(
        providerId: 'ollama',
        driver: 'ollama',
        kind: ProviderKind::Local,
        baseUrl: 'http://127.0.0.1:11434',
        modelIds: $models === [] ? ['llama3.2:3b'] : array_values($models),
        regionEvidence: RegionEvidence::localMachine(),
    );
}

function remoteOpenAi(?RegionEvidence $evidence = null): ProviderConnection
{
    return new ProviderConnection(
        providerId: 'openai',
        driver: 'openai',
        kind: ProviderKind::Remote,
        modelIds: ['gpt-5-mini'],
        regionEvidence: $evidence,
    );
}

function router(ProviderConnection ...$connections): ModelRouter
{
    return new ModelRouter(new InMemoryProviderConnections(array_values($connections)), routerRegistry());
}

// ------------------------------------------------------------------ resolving

it('routes a job to the one configured model that can do it', function (): void {
    $decision = router(localOllama(), remoteOpenAi())->resolve(
        new JobRequirements('ComposeBrief', [ModelCapability::Text, ModelCapability::Tools]),
        PrivacyMode::Custom,
    );

    expect($decision->provider->providerId)->toBe('ollama')
        ->and($decision->modelId)->toBe('llama3.2:3b')
        ->and($decision->privacyMode)->toBe(PrivacyMode::Custom)
        ->and($decision->capabilities->tools)->toBeTrue()
        ->and($decision->alternatives)->toHaveCount(1, 'the other eligible model is offered as a choice, not a fallback');
});

it('decides one provider and one model, so nothing can switch mid-run', function (): void {
    $decision = router(localOllama(), remoteOpenAi())->resolve(
        new JobRequirements('SummarizeThread'),
        PrivacyMode::Custom,
    );

    // The decision type is singular on purpose (D2 "no failover"): the run path
    // reads `provider` and `modelId`, and `alternatives` exist only so the UI can
    // offer a different choice *before* the run starts.
    expect($decision->provider)->toBeInstanceOf(ProviderConnection::class)
        ->and($decision->modelId)->toBeString();
});

it('refuses with the missing capabilities named when no model can do the job', function (): void {
    try {
        router(localOllama())->resolve(
            new JobRequirements('DescribeScreenshot', [ModelCapability::Text, ModelCapability::Vision]),
            PrivacyMode::Custom,
        );
        Assert::fail('a model without vision must not be routed a vision job');
    } catch (CapabilityUnavailableException $exception) {
        expect($exception->missing)->toBe([ModelCapability::Vision])
            ->and($exception->error->kind)->toBe(AiErrorKind::CapabilityMissing)
            ->and($exception->error->retryable)->toBeFalse()
            ->and($exception->error->message)->toContain('vision');
    }
});

it('refuses when the owner has configured no model at all', function (): void {
    expect(fn (): mixed => router()->resolve(new JobRequirements('ComposeBrief'), PrivacyMode::Custom))
        ->toThrow(CapabilityUnavailableException::class);
});

// --------------------------------------------------------------- privacy mode

it('builds only loopback local providers under Local Only', function (): void {
    $router = router(remoteOpenAi());

    expect(fn (): mixed => $router->resolve(new JobRequirements('ComposeBrief'), PrivacyMode::LocalOnly))
        ->toThrow(EgressBlockedException::class);

    expect($router->isEligibleUnder(localOllama(), PrivacyMode::LocalOnly))->toBeTrue()
        ->and($router->isEligibleUnder(remoteOpenAi(), PrivacyMode::LocalOnly))->toBeFalse();
});

it('treats only the literal loopback forms as local', function (): void {
    $router = router();

    $local = fn (string $url): ProviderConnection => new ProviderConnection(
        providerId: 'lmstudio',
        driver: 'openai-compatible',
        kind: ProviderKind::Local,
        baseUrl: $url,
        modelIds: ['byo'],
    );

    expect($router->isEligibleUnder($local('http://127.0.0.1:1234/v1'), PrivacyMode::LocalOnly))->toBeTrue()
        ->and($router->isEligibleUnder($local('http://127.1.2.3:1234/v1'), PrivacyMode::LocalOnly))->toBeTrue('all of 127.0.0.0/8')
        ->and($router->isEligibleUnder($local('http://localhost:1234/v1'), PrivacyMode::LocalOnly))->toBeTrue()
        ->and($router->isEligibleUnder($local('http://[::1]:1234/v1'), PrivacyMode::LocalOnly))->toBeTrue()
        // A hostname that merely looks local is not local: this is the
        // DNS-rebinding shape the allowlist exists to refuse.
        ->and($router->isEligibleUnder($local('http://localhost.attacker.test:1234/v1'), PrivacyMode::LocalOnly))->toBeFalse()
        ->and($router->isEligibleUnder($local('http://127.0.0.1.attacker.test/v1'), PrivacyMode::LocalOnly))->toBeFalse()
        ->and($router->isEligibleUnder($local('http://10.0.0.5:1234/v1'), PrivacyMode::LocalOnly))->toBeFalse('LAN is an explicit opt-in, never a default');
});

it('refuses a provider under Europe Restricted with the missing evidence fields named', function (): void {
    try {
        router(remoteOpenAi())->resolve(new JobRequirements('ComposeBrief'), PrivacyMode::EuRestricted);
        Assert::fail('a provider with no evidence must not be eligible');
    } catch (RegionEvidenceMissingException $exception) {
        expect($exception->error->kind)->toBe(AiErrorKind::PolicyDenied)
            ->and($exception->missingFields)->toContain('processing_region')
            ->and($exception->missingFields)->toContain('dpa_reference')
            ->and($exception->missingFields)->toContain('region_evidence_url')
            ->and($exception->missingFields)->toContain('zero_data_retention');
    }
});

it('accepts a provider under Europe Restricted once every evidence field is present and unexpired', function (): void {
    $evidence = new RegionEvidence(
        processingRegion: ProcessingRegion::Eu,
        regionMechanism: RegionMechanism::Endpoint,
        evidenceUrl: 'https://developers.openai.com/api/docs/guides/your-data',
        verifiedAt: new DateTimeImmutable('2026-10-01'),
        dpaReference: 'DPA-2026-0001',
        dpaSignedAt: new DateTimeImmutable('2026-09-01'),
        subprocessorsUrl: 'https://openai.com/policies/subprocessors',
        transferImpactRef: 'TIA-2026-0001',
        zeroDataRetention: true,
        verifiedBy: 'owner',
        expiresAt: new DateTimeImmutable('2027-10-01'),
    );

    $decision = router(remoteOpenAi($evidence))->resolve(
        new JobRequirements('ComposeBrief'),
        PrivacyMode::EuRestricted,
        new DateTimeImmutable('2026-10-13'),
    );

    expect($decision->provider->providerId)->toBe('openai');
});

it('lapses eligibility when the evidence has expired', function (): void {
    $expired = new RegionEvidence(
        processingRegion: ProcessingRegion::Eu,
        regionMechanism: RegionMechanism::Endpoint,
        evidenceUrl: 'https://example.test/residency',
        verifiedAt: new DateTimeImmutable('2025-01-01'),
        dpaReference: 'DPA-1',
        dpaSignedAt: new DateTimeImmutable('2025-01-01'),
        subprocessorsUrl: 'https://example.test/subprocessors',
        transferImpactRef: 'TIA-1',
        zeroDataRetention: true,
        verifiedBy: 'owner',
        expiresAt: new DateTimeImmutable('2026-01-01'),
    );

    expect($expired->missingFor(new DateTimeImmutable('2026-10-13')))->toBe(['expires_at (expired)']);
});

it('keeps a local runtime eligible under Europe Restricted by construction', function (): void {
    expect(RegionEvidence::localMachine()->missingFor(new DateTimeImmutable))->toBe([])
        ->and(RegionEvidence::localMachine()->regionMechanism)->toBe(RegionMechanism::LocalMachine);
});

// -------------------------------------------------------------- context window

it('refuses a prompt larger than the window with both token counts shown', function (): void {
    try {
        router(localOllama())->resolve(
            new JobRequirements('ComposeBrief', [ModelCapability::Text], contextTokens: 200_000),
            PrivacyMode::Custom,
        );
        Assert::fail('an oversized prompt must be refused, not truncated');
    } catch (ContextWindowExceededException $exception) {
        expect($exception->contextTokens)->toBe(200_000)
            ->and($exception->contextWindow)->toBe(131_072)
            ->and($exception->error->message)->toContain('200000')
            ->and($exception->error->message)->toContain('131072');
    }
});

// --------------------------------------------------------- capability sources

it('treats a model nobody knows as text and streaming only, with a 4096 window', function (): void {
    $capabilities = routerRegistry()->resolve('openai', 'some-unreleased-model');

    expect($capabilities->text)->toBeTrue()
        ->and($capabilities->streaming)->toBeTrue()
        ->and($capabilities->tools)->toBeFalse()
        ->and($capabilities->structuredOutput)->toBeFalse()
        ->and($capabilities->vision)->toBeFalse()
        ->and($capabilities->embeddings)->toBeFalse()
        ->and($capabilities->contextWindow)->toBe(4096)
        ->and($capabilities->source)->toBe(CapabilitySource::Unknown)
        ->and($capabilities->verifiedAt)->toBeNull();
});

it('reads the recommended model per provider from the registry without selecting one', function (): void {
    $registry = routerRegistry();

    expect($registry->recommendedFor('openai'))->toBe(['gpt-5-mini'])
        ->and($registry->recommendedFor('anthropic'))->toBe(['claude-opus-4-1'])
        // Local runtimes are bring-your-own: nothing is recommended, and nothing
        // is downloaded before READY (ADR-004 D8).
        ->and($registry->recommendedFor('ollama'))->toBe([]);
});

it('prefers the registry, then the probe, then the owner override', function (): void {
    $registry = routerRegistry();

    // The registry knows this model and says it has no vision.
    expect($registry->resolve('ollama', 'llama3.2:3b')->vision)->toBeFalse()
        ->and($registry->resolve('ollama', 'llama3.2:3b')->source)->toBe(CapabilitySource::Registry);

    // A probe may fill what the registry left unknown...
    $registry->overlayProbe(new ProbedCapabilities(
        driver: 'openai-compatible',
        modelId: 'byo',
        capabilities: [ModelCapability::Text, ModelCapability::Vision],
        contextWindow: 32768,
    ));

    $probed = $registry->resolve('openai-compatible', 'byo');

    expect($probed->vision)->toBeTrue()
        ->and($probed->contextWindow)->toBe(32768)
        ->and($probed->source)->toBe(CapabilitySource::Probe)
        ->and($probed->tools)->toBeFalse('a probe that is silent about tools does not grant them');

    // ... and an override may assert what neither knows, marked unverified.
    $registry->overrideWith(new CapabilityOverride(
        driver: 'openai-compatible',
        modelId: 'byo',
        capabilities: [ModelCapability::Tools],
    ));

    expect($registry->resolve('openai-compatible', 'byo')->tools)->toBeTrue();
});

it('never lets a probe or an override overturn a registry false', function (): void {
    $registry = routerRegistry();

    // The registry states `vision: false` for this model. A runtime that claims
    // otherwise, or an owner who insists, must not be able to change that: the
    // whole point of the precedence is that a verified `false` is final.
    $registry->overlayProbe(new ProbedCapabilities(
        driver: 'ollama',
        modelId: 'llama3.2:3b',
        capabilities: [ModelCapability::Vision, ModelCapability::Embeddings],
    ));
    $registry->overrideWith(new CapabilityOverride(
        driver: 'ollama',
        modelId: 'llama3.2:3b',
        capabilities: [ModelCapability::Vision, ModelCapability::Embeddings],
    ));

    $resolved = $registry->resolve('ollama', 'llama3.2:3b');

    expect($resolved->vision)->toBeFalse('a probe may not overturn a registry false')
        ->and($resolved->embeddings)->toBeFalse('nor may an owner override')
        ->and($resolved->source)->toBe(CapabilitySource::Registry);
});

it('reports an entry still under review as unverified', function (): void {
    $registry = routerRegistry();

    expect($registry->reviewRequired('anthropic', 'claude-opus-4-1'))->toBeTrue()
        ->and($registry->resolve('anthropic', 'claude-opus-4-1')->verifiedAt)->toBeNull()
        ->and($registry->reviewRequired('openai', 'gpt-5-mini'))->toBeFalse()
        ->and($registry->resolve('openai', 'gpt-5-mini')->verifiedAt)->not->toBeNull();
});

it('prices a model on the owner own machine at zero and an unpriced remote one at unknown', function (): void {
    $registry = routerRegistry();

    expect($registry->resolve('ollama', 'llama3.2:3b', ProviderKind::Local)->inputPricePerMTok)->toBe(0.0)
        ->and($registry->resolve('openai-compatible', 'byo', ProviderKind::Local)->outputPricePerMTok)->toBe(0.0)
        ->and($registry->resolve('openai', 'gpt-5-mini', ProviderKind::Remote)->inputPricePerMTok)->toBeNull()
        ->and($registry->resolve('openai-compatible', 'byo', ProviderKind::Remote)->inputPricePerMTok)->toBeNull();
});
