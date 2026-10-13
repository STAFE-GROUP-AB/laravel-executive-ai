<?php

declare(strict_types=1);

use App\Http\View\AiStateCopy;
use App\Support\Health\AiCompletionSuccessMarker;
use App\Support\Health\AiReadiness;
use App\Support\Health\AiReadinessReport;
use App\Support\Health\AiState;
use App\Support\Health\HealthService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\BriefFixtures;
use Tests\Support\CoreReadyState;
use Tests\Support\PwaFixtures;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\travelTo;

/*
 * Blueprint §0 / health contract §3, §6: no rendered PWA page may say "AI ready"
 * (or "AI redo") in any state but Ready. Every page under routes/pwa.php is
 * swept in every AiState and both languages; the Ready case is also reached
 * through the real path (RecordAiCompletionSuccess), not only a stub.
 */

uses(DatabaseTransactions::class);

/** The phrase that may exist on exactly one code path, per language. */
const AI_READY_PHRASES = ['en' => 'AI ready', 'sv' => 'AI redo'];

final class StubAiReadiness implements AiReadiness
{
    public function __construct(private readonly AiReadinessReport $report) {}

    public function report(): AiReadinessReport
    {
        return $this->report;
    }
}

function reportFor(AiState $state): AiReadinessReport
{
    return match ($state) {
        AiState::NotConfigured => AiReadinessReport::notConfigured(),
        AiState::ConfiguredUntested => AiReadinessReport::configuredUntested('ollama'),
        AiState::Error => new AiReadinessReport(AiState::Error, 'ollama', null),
        AiState::Ready => AiReadinessReport::ready('ollama', CarbonImmutable::parse('2026-10-09T05:30:00+02:00')),
    };
}

/** @return array<string, string> page label => path */
function pwaPages(): array
{
    $user = PwaFixtures::owner();
    $brief = BriefFixtures::import('canonical', PwaFixtures::organizationOf($user));
    PwaFixtures::placeholder(PwaFixtures::organizationOf($user));

    actingAs($user);

    return [
        'home' => '/home',
        'briefs' => '/briefs',
        'brief' => route('briefs.show', $brief, false),
        'settings.ai' => route('settings.ai', [], false),
        // S2-09: the four §1.2 CRM screens. They are in the sweep for the same
        // reason as the rest — the phrase may exist on exactly one code path —
        // and for one of their own: §4.2 forbids AI from blocking the CRM, so a
        // CRM page that started saying "AI ready" would be the first sign that
        // the assistant's state had leaked into a surface that does not need it.
        'crm.customers' => route('crm.customers', [], false),
        'crm.contacts' => route('crm.contacts', [], false),
        'crm.projects' => route('crm.projects', [], false),
        'crm.tasks' => route('crm.tasks', [], false),
    ];
}

/**
 * Pages that render the AI state **badge** in the chrome but not the banner.
 * The badge is a status indicator and belongs on every page; the banner is a
 * call to action and belongs where acting on it makes sense. `/settings/ai` is
 * the banner's own destination, a brief is a document, and the CRM works
 * whether or not a provider exists (§4.2) — a banner there would read as a
 * precondition for a screen that has none.
 *
 * @return list<string>
 */
function pagesWithoutTheBanner(): array
{
    return ['settings.ai', 'brief', 'briefs', 'crm.customers', 'crm.contacts', 'crm.projects', 'crm.tasks'];
}

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse(PwaFixtures::FIXTURE_NOW));
    app(HealthService::class)->forgetCached();
    app(AiCompletionSuccessMarker::class)->forget();
    config(['lea.ai.provider' => null]);
});

afterEach(function (): void {
    app()->setLocale('en');
});

it('never renders the AI ready phrase on any page while ai_ready is false', function (string $locale, AiState $state): void {
    app()->setLocale($locale);
    app()->instance(AiReadiness::class, new StubAiReadiness(reportFor($state)));
    $phrase = AI_READY_PHRASES[$locale];

    foreach (pwaPages() as $label => $path) {
        $response = get($path)->assertOk();
        $html = $response->getContent();

        expect(str_contains($html, $phrase))->toBeFalse("{$label} ({$locale}, {$state->value}) says \"{$phrase}\"")
            ->and($html)->toContain('data-ai-state="'.$state->value.'"')
            ->and($html)->toContain(AiStateCopy::label($state))
            ->and(PwaFixtures::aiOnPhoneClaims($html))->toBe([]);

        if (! in_array($label, pagesWithoutTheBanner(), true)) {
            expect($html)->toContain('data-ai-state-banner="'.$state->value.'"');
        }
    }

    get('/offline')->assertOk()->assertDontSee($phrase);
})->with(['en', 'sv'])->with([
    'not_configured' => AiState::NotConfigured,
    'configured_untested' => AiState::ConfiguredUntested,
    'error' => AiState::Error,
]);

it('renders the AI ready phrase on every page once the state is ready, and no banner', function (string $locale): void {
    app()->setLocale($locale);
    app()->instance(AiReadiness::class, new StubAiReadiness(reportFor(AiState::Ready)));
    $phrase = AI_READY_PHRASES[$locale];

    foreach (pwaPages() as $label => $path) {
        $html = get($path)->assertOk()->getContent();

        expect(str_contains($html, $phrase))->toBeTrue("{$label} ({$locale}) does not say \"{$phrase}\"")
            ->and($html)->toContain('data-ai-state="ready"')
            ->and($html)->not->toContain('data-ai-state-banner=')
            ->and($html)->not->toContain('data-ai-select')
            ->and(PwaFixtures::aiOnPhoneClaims($html))->toBe([]);
    }
})->with(['en', 'sv']);

it('reaches AI ready on the real path only after a recorded completion success', function (): void {
    $pages = pwaPages();
    config(['lea.ai.provider' => 'ollama']);

    foreach ($pages as $label => $path) {
        $html = get($path)->assertOk()->getContent();

        expect(str_contains($html, 'AI ready'))->toBeFalse("{$label} says AI ready before any completion")
            ->and(str_contains($html, 'AI setup required'))->toBeTrue("{$label} does not say AI setup required");
    }

    CoreReadyState::aiReady('ollama');

    foreach ($pages as $label => $path) {
        $html = get($path)->assertOk()->getContent();

        expect(str_contains($html, 'AI ready'))->toBeTrue("{$label} does not say AI ready after the completion")
            ->and(str_contains($html, 'AI setup required'))->toBeFalse("{$label} still says AI setup required");
    }
});

it('offers Select AI on the home banner, Reconfigure AI on error, and nothing when ready', function (): void {
    $user = PwaFixtures::owner();

    foreach ([AiState::NotConfigured, AiState::ConfiguredUntested] as $state) {
        app()->instance(AiReadiness::class, new StubAiReadiness(reportFor($state)));

        actingAs($user)->get('/home')->assertOk()
            ->assertSee('data-ai-select', false)
            ->assertSee('href="'.route('settings.ai').'"', false)
            ->assertSee('Select AI');
    }

    app()->instance(AiReadiness::class, new StubAiReadiness(reportFor(AiState::Error)));
    actingAs($user)->get('/home')->assertOk()->assertSee('Reconfigure AI')->assertSee('AI error')->assertDontSee('AI ready');

    app()->instance(AiReadiness::class, new StubAiReadiness(reportFor(AiState::Ready)));
    actingAs($user)->get('/home')->assertOk()->assertDontSee('data-ai-select', false)->assertDontSee('Select AI');
});

it('says AI redo on exactly one copy path in Swedish too', function (): void {
    app()->setLocale('sv');

    foreach (AiState::cases() as $state) {
        $text = AiStateCopy::label($state).' '.AiStateCopy::description($state).' '.AiStateCopy::actionLabel($state).' '.AiStateCopy::badgeLabel($state);

        $state === AiState::Ready
            ? expect(AiStateCopy::label($state))->toBe('AI redo')
            : expect($text)->not->toContain('AI redo');
    }

    expect(AiStateCopy::label(AiState::NotConfigured))->toBe('AI-konfiguration krävs')
        ->and(AiStateCopy::actionLabel(AiState::NotConfigured))->toBe('Välj AI')
        ->and(AiStateCopy::actionLabel(AiState::Error))->toBe('Konfigurera om AI')
        ->and(AiStateCopy::actionLabel(AiState::Ready))->toBeNull();

    $strings = collect(trans('ai', [], 'sv'))->flatten()->filter(fn (mixed $value): bool => is_string($value));

    expect($strings->filter(fn (string $value): bool => str_contains($value, 'AI redo'))->values()->all())->toBe(['AI redo']);
});
