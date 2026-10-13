<?php

declare(strict_types=1);

use App\AI\Providers\ProviderDescriptor;
use App\AI\Providers\ProviderKind;
use App\AI\Providers\SupportedProviders;
use App\Support\Health\ConfiguredProviderAiReadiness;

/*
 * Blueprint §1.1 "only options a real adapter supports" and §12.3 screen 5.
 * packaging/fixtures/ai-providers/ai-providers.json is the one catalogue; the
 * PHP mirror here and the Swift mirror (AiProviderCatalog, ChooseAiScreenTests)
 * must both equal it, and `adapter_available` must agree with the container.
 */

function providerFixture(): array
{
    return json_decode((string) file_get_contents(base_path(SupportedProviders::FIXTURE)), true, 512, JSON_THROW_ON_ERROR);
}

it('mirrors the shared provider fixture exactly', function (): void {
    $fixture = providerFixture();

    expect($fixture['schema_version'])->toBe('1.0.0')
        ->and(array_map(fn (ProviderDescriptor $provider): array => $provider->toArray(), SupportedProviders::catalogue()))->toBe($fixture['providers']);
});

it('uses unique provider ids that are valid LEA_AI_PROVIDER values', function (): void {
    $ids = array_map(fn (ProviderDescriptor $provider): string => $provider->id, SupportedProviders::catalogue());

    expect($ids)->toBe(array_values(array_unique($ids)));

    foreach ($ids as $id) {
        expect(preg_match(ConfiguredProviderAiReadiness::PROVIDER_ID_PATTERN, $id))->toBe(1, $id);
    }
});

it('marks a provider adapter-available if and only if an adapter is bound for it', function (): void {
    foreach (SupportedProviders::catalogue() as $provider) {
        expect(app()->bound(SupportedProviders::adapterBinding($provider->id)))
            ->toBe($provider->adapterAvailable, "{$provider->id}: adapter_available in the fixture must match the container binding");
    }
});

/*
 * S2-13 bound a real LaravelAiAdapter for every v1 driver, so the honesty flag is
 * true for all five catalogue rows — which is the ADR-004 D6 option list exactly.
 * The test above is what keeps that honest: each flag still has to agree with an
 * actual container binding, so a row cannot be flipped without the adapter.
 */
it('supports every provider whose adapter this build binds', function (): void {
    $providers = app(SupportedProviders::class);

    expect($providers->ids())->toBe(['ollama', 'lmstudio', 'openai', 'anthropic', 'custom'])
        ->and($providers->supported())->toEqual(SupportedProviders::catalogue())
        ->and($providers->isSupported('ollama'))->toBeTrue()
        ->and($providers->isSupported('gemini'))->toBeFalse('not in the catalogue, so never offered')
        ->and($providers->all())->toEqual(SupportedProviders::catalogue());
});

it('offers nothing at all in a build with no adapters, instead of guessing', function (): void {
    $providers = new SupportedProviders(app(), []);

    expect($providers->supported())->toBe([])
        ->and($providers->ids())->toBe([])
        ->and($providers->isSupported('ollama'))->toBeFalse();
});

/*
 * Ids outside the shipped catalogue on purpose: every catalogue row now has a
 * bound adapter, so proving that a flag alone is not enough needs a provider
 * this build deliberately does not bind. `gemini` and `openrouter` are exactly
 * that — ADR-004 D6 keeps both out of v1, and neither may appear in the UI.
 */
it('needs both the fixture flag and a real binding before a provider is offered', function (): void {
    $available = new ProviderDescriptor('ollama', 'Ollama', ProviderKind::Local, 'ollama', adapterAvailable: true);
    $flagged = new ProviderDescriptor('gemini', 'Gemini', ProviderKind::Remote, 'openai-compatible', adapterAvailable: true);
    $unflagged = new ProviderDescriptor('openrouter', 'OpenRouter', ProviderKind::Remote, 'openai-compatible', adapterAvailable: false);

    app()->bind(SupportedProviders::adapterBinding('openrouter'), fn (): stdClass => new stdClass);

    $providers = new SupportedProviders(app(), [$available, $flagged, $unflagged]);

    expect($providers->ids())->toBe(['ollama'])
        ->and($providers->isSupported('gemini'))->toBeFalse('flag without binding')
        ->and($providers->isSupported('openrouter'))->toBeFalse('binding without flag');
});

it('has a display name for every provider in both languages', function (): void {
    $ids = array_map(fn (ProviderDescriptor $provider): string => $provider->id, SupportedProviders::catalogue());

    foreach (['en', 'sv'] as $locale) {
        $names = trans('ai.providers', [], $locale);

        expect(is_array($names) ? array_keys($names) : null)->toBe($ids, "lang/{$locale}/ai.php providers");
    }
});

it('finds a catalogue row by id and nothing for an unknown id', function (): void {
    expect(SupportedProviders::find('lmstudio')?->displayName)->toBe('LM Studio')
        ->and(SupportedProviders::find('lmstudio')?->kind)->toBe(ProviderKind::Local)
        ->and(SupportedProviders::find('gemini'))->toBeNull();
});
