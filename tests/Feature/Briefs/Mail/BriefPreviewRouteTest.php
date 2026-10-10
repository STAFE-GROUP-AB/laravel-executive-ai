<?php

declare(strict_types=1);

use App\Briefs\Render\BriefMailRenderer;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\BriefFixtures;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;

/*
 * The preview routes are behind `auth` and BriefPolicy::view: a member of the
 * brief's organization sees the exact mail parts; a guest and a member of
 * another organization do not (§11 policies on every read; §5.6 criterion 4).
 */

uses(DatabaseTransactions::class);

function memberOf(Organization $organization): User
{
    $user = User::factory()->create();
    Membership::factory()->owner()->for($organization)->for($user)->create();

    return $user;
}

it('requires authentication', function (): void {
    $brief = BriefFixtures::import('canonical');

    getJson("/briefs/{$brief->id}/preview/mail")->assertUnauthorized();
    getJson("/briefs/{$brief->id}/preview/text")->assertUnauthorized();
});

it('forbids a member of another organization', function (): void {
    $brief = BriefFixtures::import('canonical');
    $outsider = memberOf(Organization::factory()->create());

    actingAs($outsider);

    get("/briefs/{$brief->id}/preview/mail")->assertForbidden();
    get("/briefs/{$brief->id}/preview/text")->assertForbidden();
});

it('returns 404 for an unknown brief', function (): void {
    actingAs(memberOf(Organization::factory()->create()));

    get('/briefs/01M4FJ7GY0PZH6YA3D9574XN5K/preview/mail')->assertNotFound();
});

it('serves the exact html part with hardening headers to a member', function (): void {
    $organization = Organization::factory()->create();
    $brief = BriefFixtures::import('canonical', $organization);
    $expected = app(BriefMailRenderer::class)->renderBrief($brief);

    actingAs(memberOf($organization));

    $response = get(route('briefs.previewMail', $brief))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('X-LEA-Brief-Preview', 'nothing-sent');

    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('Content-Security-Policy'))->toContain("default-src 'none'")
        ->and($response->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'self'")
        ->and($response->getContent())->toBe($expected->html);
});

it('serves the exact plaintext part as text/plain to a member', function (): void {
    $organization = Organization::factory()->create();
    $brief = BriefFixtures::import('multilingual-de', $organization);
    $expected = app(BriefMailRenderer::class)->renderBrief($brief);

    actingAs(memberOf($organization));

    $response = get(route('briefs.previewText', $brief))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    expect($response->getContent())->toBe($expected->text)
        ->and($response->getContent())->toContain('Jörg Müßig');
});
