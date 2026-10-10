<?php

declare(strict_types=1);

use App\AI\Providers\ProviderDescriptor;
use App\AI\Providers\ProviderKind;
use App\AI\Providers\SupportedProviders;
use App\Support\Health\AiCompletionSuccessMarker;
use App\Support\Health\HealthService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\CoreReadyState;
use Tests\Support\PwaFixtures;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\travelTo;

/*
 * /settings/ai — blueprint §12.3 screen 5 as the PWA shows it: neutral options,
 * nothing preselected, only providers a real adapter supports (§1.1), and an
 * honest sentence when there are none.
 */

uses(DatabaseTransactions::class);

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse(PwaFixtures::FIXTURE_NOW));
    app(HealthService::class)->forgetCached();
    app(AiCompletionSuccessMarker::class)->forget();
    config(['lea.ai.provider' => null]);
});

afterEach(function (): void {
    app()->setLocale('en');
});

it('requires an authenticated user', function (): void {
    get('/settings/ai')->assertRedirect(route('login'));
});

it('renders screen 5 with nothing preselected and says honestly that no adapter is available yet', function (): void {
    $response = actingAs(PwaFixtures::owner())->get('/settings/ai')->assertOk();
    $html = $response->getContent();
    $xpath = PwaFixtures::xpath($html);

    $response->assertSee('Choose AI')
        ->assertSee('data-ai-preselected="none"', false)
        ->assertSee('data-ai-option="skip"', false)
        ->assertSee('data-ai-option-none', false)
        ->assertSee('No AI provider adapter is bound in this build yet')
        ->assertSee('No provider is selected.')
        ->assertDontSee('AI ready')
        ->assertDontSee('data-ai-select', false);

    expect($xpath->query('//input[@type="radio"]')->length)->toBe(0)
        ->and($xpath->query('//*[@checked]')->length)->toBe(0)
        ->and($xpath->query('//*[@aria-checked="true"]')->length)->toBe(0)
        ->and($xpath->query('//*[@aria-selected="true"]')->length)->toBe(0)
        ->and($xpath->query('//*[@data-ai-option][not(@data-ai-option="skip")]')->length)->toBe(0)
        ->and(PwaFixtures::aiOnPhoneClaims($html))->toBe([]);
});

it('lists only providers whose adapter is both flagged available and bound, still with nothing preselected', function (): void {
    app()->bind(SupportedProviders::adapterBinding('ollama'), fn (): stdClass => new stdClass);
    app()->instance(SupportedProviders::class, new SupportedProviders(app(), [
        new ProviderDescriptor('ollama', 'Ollama', ProviderKind::Local, 'ollama', adapterAvailable: true),
        new ProviderDescriptor('anthropic', 'Anthropic', ProviderKind::Remote, 'anthropic', adapterAvailable: true),
        new ProviderDescriptor('openai', 'OpenAI', ProviderKind::Remote, 'openai', adapterAvailable: false),
    ]));

    $response = actingAs(PwaFixtures::owner())->get('/settings/ai')->assertOk();
    $xpath = PwaFixtures::xpath($response->getContent());

    $response->assertSee('data-ai-option="ollama"', false)
        ->assertSee('data-ai-kind="local"', false)
        ->assertSee('Runs on your Mac. Nothing is sent to a provider.')
        ->assertDontSee('data-ai-option="anthropic"', false)
        ->assertDontSee('data-ai-option="openai"', false)
        ->assertDontSee('data-ai-option-none', false)
        ->assertSee('data-ai-preselected="none"', false);

    expect($xpath->query('//*[@checked or @aria-checked="true" or @aria-selected="true"]')->length)->toBe(0);
});

it('names the selected provider and keeps saying AI is not ready until a completion succeeded', function (): void {
    config(['lea.ai.provider' => 'ollama']);
    $user = PwaFixtures::owner();

    actingAs($user)->get('/settings/ai')->assertOk()
        ->assertSee('data-ai-current-provider="ollama"', false)
        ->assertSee('Ollama is selected. No completion has succeeded yet, so AI is not ready.')
        ->assertSee('data-ai-state-banner="configured_untested"', false)
        ->assertDontSee('AI ready');

    CoreReadyState::aiReady('ollama');

    actingAs($user)->get('/settings/ai')->assertOk()
        ->assertSee('Ollama is selected and has completed a request.')
        ->assertSee('AI ready')
        ->assertDontSee('data-ai-state-banner=', false);
});

it('renders in Swedish from the same keys', function (): void {
    app()->setLocale('sv');

    actingAs(PwaFixtures::owner())->get('/settings/ai')->assertOk()
        ->assertSee('Välj AI')
        ->assertSee('AI-konfiguration krävs')
        ->assertSee('Fortsätt utan AI')
        ->assertSee('Ingen AI-adapter är inkopplad')
        ->assertDontSee('AI redo')
        ->assertDontSee('AI ready');
});

it('is network-only like every other PWA page', function (): void {
    $source = (string) file_get_contents(resource_path('js/service-worker.js'));

    preg_match('/const NEVER_CACHE = \[(.*?)\];/s', $source, $match);

    expect($match[1] ?? '')->toContain('"/settings"');
});
