<?php

declare(strict_types=1);

use App\Support\Health\HealthService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Tests\Support\BriefFixtures;
use Tests\Support\PwaFixtures;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\travelTo;
use function Pest\Laravel\withoutExceptionHandling;

uses(DatabaseTransactions::class);

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse(PwaFixtures::FIXTURE_NOW));
    app(HealthService::class)->forgetCached();
    config(['lea.ai.provider' => null]);
});

it('requires an authenticated user', function (): void {
    if (Route::has('login')) {
        get('/home')->assertRedirect(route('login'));

        return;
    }

    // Until S1-02 registers /login, the guest redirect has no target: the request still
    // never renders, it fails at the auth middleware or at the redirect's route lookup.
    withoutExceptionHandling();
    $rendered = false;

    try {
        get('/home');
        $rendered = true;
    } catch (AuthenticationException|RouteNotFoundException) {
        // expected: the guest never reaches the page
    }

    expect($rendered)->toBeFalse('A guest reached /home.')
        ->and(auth()->check())->toBeFalse();
});

it('renders the latest brief summary from the server with its version, timezone and fresh state', function (): void {
    $user = PwaFixtures::owner();
    $brief = BriefFixtures::import('canonical', PwaFixtures::organizationOf($user));

    $response = actingAs($user)->get('/home')->assertOk();
    $html = $response->getContent();

    $response->assertSee($brief->subject)
        ->assertSee(__('brief.version', ['version' => 1], 'sv'))
        ->assertSee('2026-10-09T05:30:00+02:00', false)
        ->assertSee('data-brief-state="fresh"', false)
        ->assertSee('data-brief-version="1"', false)
        ->assertSee('data-brief-generated-at="2026-10-09T05:30:00+02:00"', false)
        ->assertSee(route('briefs.show', $brief), false)
        ->assertSee(__('brief.privacy_modes.LOCAL_ONLY', [], 'sv'));

    expect(PwaFixtures::aiOnPhoneClaims($html))->toBe([]);
});

it('marks a stale brief as stale', function (): void {
    $user = PwaFixtures::owner();
    BriefFixtures::import('stale', PwaFixtures::organizationOf($user));

    actingAs($user)->get('/home')->assertOk()
        ->assertSee('data-brief-state="stale"', false)
        ->assertSee('Stale');
});

it('shows the AI setup required banner and never AI ready while no provider is configured', function (): void {
    $user = PwaFixtures::owner();
    PwaFixtures::placeholder(PwaFixtures::organizationOf($user));

    $response = actingAs($user)->get('/home')->assertOk();

    $response->assertSee('AI setup required')
        ->assertSee('data-ai-state-banner="not_configured"', false)
        ->assertSee('data-ai-state="not_configured"', false)
        ->assertDontSee('AI ready')
        ->assertSee('Placeholder');

    expect(PwaFixtures::aiOnPhoneClaims($response->getContent()))->toBe([]);
});

it('still shows AI setup required when a provider is selected but untested', function (): void {
    config(['lea.ai.provider' => 'ollama']);
    $user = PwaFixtures::owner();

    actingAs($user)->get('/home')->assertOk()
        ->assertSee('AI setup required')
        ->assertSee('data-ai-state-banner="configured_untested"', false)
        ->assertDontSee('AI ready');
});

it('shows zero pending approvals without a badge in the header', function (): void {
    $user = PwaFixtures::owner();

    actingAs($user)->get('/home')->assertOk()
        ->assertSee('data-pending-approvals-count>0<', false)
        ->assertDontSee('data-pending-approvals=', false)
        ->assertSee(route('briefs'), false);
});

it('renders the nothing-yet state when the organization has no brief', function (): void {
    $user = PwaFixtures::owner();

    $response = actingAs($user)->get('/home')->assertOk();

    $response->assertSee('No brief yet')
        ->assertSee('data-empty-state', false)
        ->assertDontSee('data-latest-brief', false);

    expect(PwaFixtures::aiOnPhoneClaims($response->getContent()))->toBe([]);
});

it('ships the app shell: skip link, one main landmark, manifest and Apple meta tags, no inline scripts', function (): void {
    $user = PwaFixtures::owner();

    $response = actingAs($user)->get('/home')->assertOk();
    $html = $response->getContent();
    $xpath = PwaFixtures::xpath($html);

    expect($xpath->query('//main[@id="main"]')->length)->toBe(1)
        ->and($xpath->query('//a[@href="#main"]')->length)->toBe(1)
        ->and($xpath->query('//link[@rel="manifest"][@href="/manifest.webmanifest"]')->length)->toBe(1)
        ->and($xpath->query('//meta[@name="apple-mobile-web-app-capable"][@content="yes"]')->length)->toBe(1)
        ->and($xpath->query('//meta[@name="apple-mobile-web-app-title"]')->length)->toBe(1)
        ->and($xpath->query('//link[@rel="apple-touch-icon"]')->length)->toBe(1)
        ->and($xpath->query('//meta[@name="viewport"][contains(@content, "viewport-fit=cover")]')->length)->toBe(1)
        ->and($xpath->query('//meta[@name="theme-color"]')->length)->toBe(2)
        ->and($xpath->query('//nav[@aria-label="Primary"]')->length)->toBe(2)
        ->and($xpath->query('//script[not(@src)]')->length)->toBe(0)
        ->and($xpath->query('//*[@style]')->length)->toBe(0)
        ->and($xpath->query('//*[@onclick or @onload or @onsubmit]')->length)->toBe(0);
});

it('posts the logout form to the named route only once S1-02 registers it', function (): void {
    $user = PwaFixtures::owner();

    $response = actingAs($user)->get('/home')->assertOk();

    Route::has('logout')
        ? $response->assertSee('action="'.route('logout').'"', false)
        : $response->assertDontSee('Log out');
});
