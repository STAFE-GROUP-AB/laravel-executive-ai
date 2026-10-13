<?php

declare(strict_types=1);

use App\CRM\Deletions\CrmDeletions;
use App\CRM\Eloquent\CrmEntity;
use App\CRM\Eloquent\CrmRepository;
use App\CRM\Eloquent\EloquentWorkspaceRepository;
use App\CRM\Eloquent\WorkspaceQueries;
use App\Http\View\AiStateCopy;
use App\Livewire\Crm\Contacts;
use App\Livewire\Crm\CrmScreen;
use App\Livewire\Crm\Customers;
use App\Livewire\Crm\Projects;
use App\Livewire\Crm\Tasks;
use App\Models\CRM\Customer;
use App\Support\Health\AiReadiness;
use App\Support\Health\AiReadinessReport;
use App\Support\Health\AiState;
use App\Support\Health\HealthService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\Security\Support\PolicySurface;
use Tests\Support\CrmRecords;
use Tests\Support\PwaFixtures;

use function Pest\Laravel\actingAs;

uses(DatabaseTransactions::class);

/*
 * Blueprint §4.2: **"AI unavailable" must never block the CRM.** The local CRM
 * is the system of record; the assistant is a reader of it. A screen that
 * refused to open, or that told the owner to configure a provider before they
 * could add a customer, would make the product's most basic function depend on
 * the part most likely to be missing on day one.
 *
 * Two kinds of assertion here, and the second is the one that lasts:
 *
 *  1. **Behavioural** — every §1.2 CRM route answers 200 and every write
 *     succeeds in each `ai_state` that is not `ready`, and no copy on the page
 *     says AI is required.
 *  2. **Structural** — the CRM screens name nothing in the AI layer at all.
 *     A dependency cannot be "optional" if it is there: this is what stops a
 *     later commit from reading `AiReadiness` inside a CRM screen and only
 *     failing when somebody re-reads the behavioural cases.
 *
 * `AiReadyGateTest` is the other half: it sweeps these same pages for the "AI
 * ready" phrase in every state and both languages, so the phrase stays on
 * exactly one code path (ai-state.md §3).
 */

final class UnavailableAiReadiness implements AiReadiness
{
    public function __construct(private readonly AiReadinessReport $report) {}

    public function report(): AiReadinessReport
    {
        return $this->report;
    }
}

function crmAiState(AiState $state): void
{
    app()->instance(AiReadiness::class, new UnavailableAiReadiness(match ($state) {
        AiState::NotConfigured => AiReadinessReport::notConfigured(),
        AiState::ConfiguredUntested => AiReadinessReport::configuredUntested('ollama'),
        AiState::Error => new AiReadinessReport(AiState::Error, 'ollama', null),
        AiState::Ready => AiReadinessReport::ready('ollama', CarbonImmutable::now()),
    }));
}

/** @return list<string> */
function crmRoutes(): array
{
    return array_map(
        static fn (CrmEntity $entity): string => route($entity->routeName(), [], false),
        CrmEntity::cases(),
    );
}

beforeEach(function (): void {
    app(HealthService::class)->forgetCached();
    config(['lea.ai.provider' => null]);
});

dataset('ai states that are not ready', [
    'not_configured' => AiState::NotConfigured,
    'configured_untested' => AiState::ConfiguredUntested,
    'error' => AiState::Error,
]);

it('opens every CRM route while AI is unavailable', function (AiState $state): void {
    crmAiState($state);

    $user = PwaFixtures::owner();
    CrmRecords::customer(PwaFixtures::organizationOf($user), 'Nordlund Logistik');

    foreach (crmRoutes() as $path) {
        $response = actingAs($user)->get($path)->assertOk();
        $html = (string) $response->getContent();

        expect($html)->toContain('data-ai-state="'.$state->value.'"')
            ->and($html)->toContain(AiStateCopy::label($state))
            // §1.2: no copy may claim AI runs on the phone or in the browser.
            ->and(PwaFixtures::aiOnPhoneClaims($html))->toBe([]);
    }
})->with('ai states that are not ready');

it('creates, updates and deletes a record while AI is unavailable', function (AiState $state): void {
    crmAiState($state);

    $user = PwaFixtures::owner();

    Livewire::actingAs($user)->test(Customers::class)
        ->call('startCreate')
        ->set('form.display_name', 'Kvarnholmen Entreprenad')
        ->set('form.legal_name', 'Kvarnholmen Entreprenad AB')
        ->call('save')
        ->assertHasNoErrors();

    $customer = Customer::query()->sole();

    Livewire::actingAs($user)->test(Customers::class)
        ->call('startEdit', (string) $customer->getKey())
        ->set('form.display_name', 'Kvarnholmen Entreprenad & Bygg')
        ->call('save')
        ->assertHasNoErrors();

    Livewire::actingAs($user)->test(Customers::class)
        ->call('proposeDeletion', (string) $customer->getKey())
        ->call('approveDeletion', (string) $customer->getKey());

    expect(Customer::withTrashed()->sole()->trashed())->toBeTrue()
        ->and(Customer::withTrashed()->sole()->display_name)->toBe('Kvarnholmen Entreprenad & Bygg');
})->with('ai states that are not ready');

it('searches while AI is unavailable, because the index is the database and not a model', function (AiState $state): void {
    crmAiState($state);

    $user = PwaFixtures::owner();
    $organization = PwaFixtures::organizationOf($user);
    CrmRecords::customer($organization, 'Göteborg Frakt');
    CrmRecords::customer($organization, 'Malmö Montage');

    Livewire::actingAs($user)->test(Customers::class)
        ->set('search', 'goteborg')
        ->assertSee('Göteborg Frakt')
        ->assertDontSee('Malmö Montage');
})->with('ai states that are not ready');

it('never tells the owner that AI is required to use the CRM', function (AiState $state): void {
    crmAiState($state);

    $user = PwaFixtures::owner();

    // Phrases that would make a working screen read as blocked. The banner the
    // shell shows is a *banner* — "AI setup required" is about the AI surface,
    // and it carries a link to /settings/ai rather than standing between the
    // owner and a customer record.
    $blocking = [
        'requires ai',
        'ai is required',
        'configure ai first',
        'available once ai',
        'unavailable until ai',
        'needs an ai provider',
        'kräver ai',
        'ai krävs för',
    ];

    foreach (crmRoutes() as $path) {
        $text = mb_strtolower(strip_tags((string) actingAs($user)->get($path)->assertOk()->getContent()));

        foreach ($blocking as $phrase) {
            expect(str_contains($text, $phrase))->toBeFalse("{$path} says \"{$phrase}\"");
        }
    }
})->with('ai states that are not ready');

it('still works when every CRM route is opened with no AI provider configured at all', function (): void {
    // No AiReadiness stub: the real binding, with no provider in config, which
    // is the state a fresh install is in before the owner chooses one.
    $user = PwaFixtures::owner();
    CrmRecords::task(PwaFixtures::organizationOf($user), 'Ring Elin');

    foreach (crmRoutes() as $path) {
        actingAs($user)->get($path)->assertOk();
    }

    expect(Route::has('crm.customers'))->toBeTrue();
});

it('names nothing in the AI layer from a CRM screen', function (): void {
    // The structural half. §4.2 cannot be satisfied by a page that merely
    // happens to render: a screen that read AiReadiness would work today and
    // break the first time the reading became a condition. So the CRM screens
    // reach the AI namespaces not at all.
    $forbidden = ['App\\AI\\', 'AiReadiness', 'AiState', 'ModelRouter', 'ProviderRegistry', 'AiRun'];

    foreach ([CrmScreen::class, Customers::class, Contacts::class, Projects::class, Tasks::class] as $screen) {
        $source = PolicySurface::sourceOf($screen);

        foreach ($forbidden as $needle) {
            expect(str_contains($source, $needle))->toBeFalse("{$screen} names {$needle}");
        }
    }

    // And neither does the data layer the screens write through.
    foreach ([
        CrmRepository::class,
        WorkspaceQueries::class,
        EloquentWorkspaceRepository::class,
        CrmDeletions::class,
    ] as $class) {
        $source = PolicySurface::sourceOf($class);

        foreach (['App\\AI\\', 'AiReadiness', 'ModelRouter'] as $needle) {
            expect(str_contains($source, $needle))->toBeFalse("{$class} names {$needle}");
        }
    }
});

it('keeps the CRM copy free of any claim that AI is needed, in both languages', function (string $locale): void {
    app()->setLocale($locale);

    /** @var array<array-key, mixed> $strings */
    $strings = trans('crm', [], $locale);
    $flattened = collect(Arr::dot($strings))->filter(fn (mixed $value): bool => is_string($value));

    expect($flattened)->not->toBeEmpty();

    foreach ($flattened as $key => $value) {
        $lower = mb_strtolower($value);

        expect(str_contains($lower, ' ai '))->toBeFalse("crm.{$key} mentions AI")
            ->and(str_starts_with($lower, 'ai '))->toBeFalse("crm.{$key} mentions AI");
    }

    app()->setLocale('en');
})->with(['en', 'sv']);
