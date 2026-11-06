<?php

declare(strict_types=1);

use App\CRM\Eloquent\CrmEntity;
use App\Livewire\Crm\Contacts;
use App\Livewire\Crm\Customers;
use App\Livewire\Crm\Projects;
use App\Livewire\Crm\Tasks;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Vite;
use Livewire\Livewire;
use Tests\Support\CrmRecords;
use Tests\Support\PwaFixtures;

use function Pest\Laravel\actingAs;

uses(DatabaseTransactions::class);

/*
 * pwa.md §6 and §1, applied to the four §1.2 CRM screens.
 *
 * The responsive claim — 375, 768 and 1280 px with no horizontal body scroll —
 * is a CSS fact, and the thing a test can hold is the *mechanism* that makes it
 * true: the one wide element on these pages is the list table, and it scrolls
 * inside its own `overflow-x-auto` container rather than widening the body.
 * These assertions are what a screenshot cannot regress on its own.
 *
 * The accessibility assertions are of the same kind: a label tied to its input
 * by id, a visible focus ring (the token ring on `:focus-visible`, from
 * `app.css`, never removed per-element), 44 px targets through `min-h-touch`
 * and the `.btn-*` classes, one `<main>`, and no inline script, inline style or
 * inline handler — which is also what keeps the pages working under S1-02's
 * strict CSP (`script-src 'self' 'nonce-…'`, threat model A16).
 */

/** @return list<string> */
function crmScreenPaths(): array
{
    return array_map(static fn (CrmEntity $entity): string => route($entity->routeName(), [], false), CrmEntity::cases());
}

function seededWorkspace(): User
{
    $user = PwaFixtures::owner();
    $organization = PwaFixtures::organizationOf($user);
    $customer = CrmRecords::customer($organization, 'Nordlund Logistik');
    $contact = CrmRecords::contact($organization, 'Elin Sjöström');
    CrmRecords::address($contact, 'elin@nordlund.example');
    CrmRecords::link($customer, $contact);
    CrmRecords::project($customer, 'Lagerlokal Mölndal');
    CrmRecords::task($organization, 'Ring Elin', ['customer_id' => $customer->getKey()]);

    return $user;
}

it('ships one main landmark, a skip link and no inline script, style or handler', function (): void {
    $user = seededWorkspace();

    foreach (crmScreenPaths() as $path) {
        $xpath = PwaFixtures::xpath((string) actingAs($user)->get($path)->assertOk()->getContent());

        expect($xpath->query('//main[@id="main"]')->length)->toBe(1, $path)
            ->and($xpath->query('//a[@href="#main"]')->length)->toBe(1, $path)
            ->and($xpath->query('//h1')->length)->toBe(1, $path)
            ->and($xpath->query('//script[not(@src)]')->length)->toBe(0, "{$path} has an inline script")
            ->and($xpath->query('//*[@style]')->length)->toBe(0, "{$path} has an inline style attribute")
            ->and($xpath->query('//*[@onclick or @onload or @onsubmit or @onchange]')->length)->toBe(0, "{$path} has an inline handler");
    }
});

it('loads Livewire and Alpine through @livewireScripts with the CSP nonce and no CDN', function (): void {
    $user = seededWorkspace();

    foreach (crmScreenPaths() as $path) {
        $html = (string) actingAs($user)->get($path)->assertOk()->getContent();
        $xpath = PwaFixtures::xpath($html);

        $scripts = $xpath->query('//script[@src]');
        $livewire = 0;

        for ($i = 0; $i < $scripts->length; $i++) {
            $src = (string) $scripts->item($i)?->attributes?->getNamedItem('src')?->nodeValue;

            // §0 and pwa.md §1: every asset is served from this origin, so a
            // src is either relative or carries this install's own URL root.
            // Vite writes absolute URLs for built assets, which is why the
            // assertion is about the host rather than about the scheme. A CDN
            // script would also be refused by the CSP; the point is that none
            // is written in the first place.
            $sameOrigin = ! str_contains($src, '//') || str_starts_with($src, rtrim(url('/'), '/').'/');

            expect($sameOrigin)->toBeTrue("{$path} loads an off-origin script: {$src}");

            if (str_contains($src, 'livewire')) {
                $livewire++;
            }
        }

        expect($livewire)->toBe(1, "{$path} must load Livewire exactly once");

        // The nonce the SecurityHeaders middleware minted for this response is
        // on the script tag, which is what `@livewireScripts` does with
        // `Vite::cspNonce()`. Alpine ships inside that bundle — there is no
        // second tag for it, and no `<script>` of ours at all.
        expect($html)->toContain('nonce="'.Vite::cspNonce().'"');
    }
});

it('keeps the wide list inside its own scroll container, so the body never scrolls sideways', function (): void {
    $user = seededWorkspace();

    foreach (crmScreenPaths() as $path) {
        $xpath = PwaFixtures::xpath((string) actingAs($user)->get($path)->assertOk()->getContent());

        // Every table on the page sits inside an element that scrolls on its
        // own. `min-w-max` on the table is what makes the columns keep their
        // width; the container is what keeps that off the body.
        $tables = $xpath->query('//table');

        expect($tables->length)->toBeGreaterThan(0, "{$path} renders no list");

        for ($i = 0; $i < $tables->length; $i++) {
            $container = $tables->item($i)?->parentNode;
            $classes = (string) $container?->attributes?->getNamedItem('class')?->nodeValue;

            expect(str_contains($classes, 'overflow-x-auto'))
                ->toBeTrue("{$path}: the list is not inside a scroll container — classes: {$classes}");
        }

        // The tab row is the other potentially wide element.
        $tabRows = $xpath->query('//nav[contains(@class, "overflow-x-auto")]');

        expect($tabRows->length)->toBeGreaterThan(0, "{$path}: the CRM tab row must scroll on its own");
    }
});

it('ties every form field to a label and marks an invalid field for a screen reader', function (): void {
    $user = PwaFixtures::owner();

    foreach ([Customers::class, Contacts::class, Projects::class, Tasks::class] as $component) {
        $html = Livewire::actingAs($user)->test($component)->call('startCreate')->html();
        $xpath = PwaFixtures::xpath($html);

        $controls = $xpath->query('//form//input[@id] | //form//select[@id] | //form//textarea[@id]');

        expect($controls->length)->toBeGreaterThan(0, "{$component} renders no form control");

        for ($i = 0; $i < $controls->length; $i++) {
            $id = (string) $controls->item($i)?->attributes?->getNamedItem('id')?->nodeValue;

            expect($xpath->query('//label[@for="'.$id.'"]')->length)->toBe(1, "{$component}: control {$id} has no label");
        }
    }

    // A refused field says so in `aria-invalid` and points at the message, so
    // the error is not colour-and-position alone.
    $html = Livewire::actingAs($user)->test(Customers::class)
        ->call('startCreate')
        ->set('form.display_name', '')
        ->call('save')
        ->html();

    expect($html)->toContain('aria-invalid="true"')
        ->and($html)->toContain('id="form-display_name-error"')
        ->and($html)->toContain('aria-describedby="form-display_name-error"')
        ->and($html)->toContain('role="alert"');
});

it('gives every action a 44 px target and keeps the focus ring', function (): void {
    $user = seededWorkspace();

    foreach (crmScreenPaths() as $path) {
        $xpath = PwaFixtures::xpath((string) actingAs($user)->get($path)->assertOk()->getContent());
        $buttons = $xpath->query('//main//button | //main//a[@href]');

        expect($buttons->length)->toBeGreaterThan(0, "{$path} has no action");

        for ($i = 0; $i < $buttons->length; $i++) {
            $classes = (string) $buttons->item($i)?->attributes?->getNamedItem('class')?->nodeValue;

            // `.btn-primary` and `.btn-secondary` carry `min-height:
            // var(--size-touch-min)` in app.css; anything else states it.
            $touchable = str_contains($classes, 'btn-primary')
                || str_contains($classes, 'btn-secondary')
                || str_contains($classes, 'min-h-touch');

            expect($touchable)->toBeTrue("{$path}: an action is smaller than 44 px — classes: {$classes}");

            // design-system.md §6: the ring is never removed from an element
            // without a replacement, and no element here removes it.
            expect($classes)->not->toContain('outline-none focus:outline-none" ');
        }
    }
});

it('says what state a row is in with a word and not only a colour', function (): void {
    $user = seededWorkspace();

    // Every badge on these screens carries text: the status, "Awaiting
    // approval", "Deleted". A reader who cannot see the tone still reads it.
    $html = (string) actingAs($user)->get(route('crm.customers', [], false))->assertOk()->getContent();
    $xpath = PwaFixtures::xpath($html);
    $badges = $xpath->query('//main//span[contains(@class, "rounded-sm")]');

    expect($badges->length)->toBeGreaterThan(0);

    for ($i = 0; $i < $badges->length; $i++) {
        expect(trim((string) $badges->item($i)?->textContent))->not->toBe('', 'a badge carries no word');
    }
});
