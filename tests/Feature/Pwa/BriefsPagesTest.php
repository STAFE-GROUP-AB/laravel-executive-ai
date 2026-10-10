<?php

declare(strict_types=1);

use App\Briefs\BriefContentHash;
use App\Briefs\BriefDocumentMapper;
use App\Models\Organization;
use App\Models\User;
use App\Support\Data\Provenance;
use App\Support\Data\ProvenanceOrigin;
use App\Support\Health\HealthService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\Support\BriefFixtures;
use Tests\Support\PwaFixtures;
use Tests\Support\Snapshot;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

uses(DatabaseTransactions::class);

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse(PwaFixtures::FIXTURE_NOW));
    app(HealthService::class)->forgetCached();
    config(['lea.ai.provider' => null]);
});

function collapseWhitespace(?string $text): string
{
    return trim((string) preg_replace('/\s+/u', ' ', (string) $text));
}

it('lists every visible version with document id, version, generated_at and delivery state', function (): void {
    $user = PwaFixtures::owner();
    $organization = PwaFixtures::organizationOf($user);
    $canonical = BriefFixtures::import('canonical', $organization);
    $failed = BriefFixtures::import('delivery-failed', $organization);
    $stale = BriefFixtures::import('stale', $organization);

    $response = actingAs($user)->get('/briefs')->assertOk();
    $xpath = PwaFixtures::xpath($response->getContent());
    $rows = $xpath->query('//tr[@data-brief-row]');

    expect($rows->length)->toBe(3);

    foreach ([$canonical, $failed, $stale] as $brief) {
        $row = $xpath->query("//tr[@data-brief-row][@data-brief-id='{$brief->id}']")->item(0);

        expect($row)->not->toBeNull();
        /** @var DOMElement $row */
        expect($row->getAttribute('data-brief-document-id'))->toBe($brief->document_id)
            ->and($row->getAttribute('data-brief-version'))->toBe((string) $brief->version)
            ->and($row->getAttribute('data-delivery-state'))->toBe($brief->delivery_state->value)
            ->and($row->getAttribute('data-brief-generated-at'))->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+-]\d{2}:\d{2})$/');
    }

    $response->assertSee($canonical->document_id)
        ->assertSee('2026-10-09T05:30:00+02:00', false)
        ->assertSee('Delivery failed')
        ->assertSee('data-brief-state="stale"', false)
        ->assertSee(route('briefs.show', $canonical), false);

    expect(PwaFixtures::aiOnPhoneClaims($response->getContent()))->toBe([]);
});

it('renders the canonical brief from the parity payload: every item in order with a source or marker', function (): void {
    $user = PwaFixtures::owner();
    $brief = BriefFixtures::import('canonical', PwaFixtures::organizationOf($user));
    $document = BriefFixtures::load('canonical');

    $response = actingAs($user)->get(route('briefs.show', $brief))->assertOk();
    $html = $response->getContent();
    $xpath = PwaFixtures::xpath($html);

    $response->assertSee($document['subject'])
        ->assertSee($document['summary'])
        ->assertSee('data-brief-version="1"', false)
        ->assertSee(__('brief.version', ['version' => 1], 'sv'))
        ->assertSee('data-brief-generated-at="2026-10-09T05:30:00+02:00"', false)
        ->assertSee('datetime="2026-10-09T05:30:00+02:00"', false)
        ->assertSee('data-brief-content-hash="'.$document['content_hash'].'"', false)
        ->assertSee('data-privacy-mode="LOCAL_ONLY"', false)
        ->assertSee(__('brief.privacy_modes.LOCAL_ONLY', [], 'sv'))
        ->assertSee('lang="sv"', false)
        ->assertSeeInOrder(array_column($document['items'], 'title'));

    $items = $xpath->query('//li[@data-brief-item]');

    expect($items->length)->toBe(count($document['items']));

    foreach ($document['items'] as $position => $item) {
        /** @var DOMElement $node */
        $node = $items->item($position);

        expect($node->getAttribute('data-item-id'))->toBe($item['id'])
            ->and($node->getAttribute('data-position'))->toBe((string) $position)
            ->and($node->getAttribute('data-kind'))->toBe($item['kind'])
            ->and($node->getAttribute('data-priority'))->toBe($item['priority']);

        $sources = $xpath->query('.//*[@data-source-ref]', $node)->length;
        $markers = $xpath->query('.//*[@data-uncertainty]', $node)->length;

        expect($sources + $markers)->toBeGreaterThan(0, "item {$item['id']} shows neither a source nor an uncertainty marker")
            ->and($sources)->toBe(count($item['source_refs'] ?? []))
            ->and($markers)->toBe(isset($item['uncertainty']) ? 1 : 0)
            ->and($xpath->query('.//*[@data-action-id]', $node)->length)->toBe(count($item['action_ids'] ?? []));

        $body = $xpath->query('.//*[@data-item-body]', $node)->item(0)?->textContent;

        expect(collapseWhitespace($body))->toBe(collapseWhitespace($item['body']));

        if (isset($item['due_at'])) {
            expect($xpath->query(".//time[@data-due-at][@datetime='{$item['due_at']}']", $node)->length)->toBe(1);
        }
    }

    foreach ($document['sources'] as $source) {
        $response->assertSee($source['uri'], false);

        if (isset($source['account_label'])) {
            $response->assertSee($source['account_label']);
        }
    }

    expect(PwaFixtures::aiOnPhoneClaims($html))->toBe([]);
});

it('exports the parity payload it rendered from, byte-identical to the mail snapshot', function (): void {
    $user = PwaFixtures::owner();
    $brief = BriefFixtures::import('canonical', PwaFixtures::organizationOf($user));

    $response = actingAs($user)->get(route('briefs.parity', $brief))->assertOk()
        ->assertHeader('Content-Type', 'application/json; charset=UTF-8')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->getContent())->toBe(rtrim((string) file_get_contents(Snapshot::path('brief-canonical.parity.json'))));

    $page = actingAs($user)->get(route('briefs.show', $brief))->assertOk();
    $hash = PwaFixtures::xpath($page->getContent())->query('//article[@data-brief]/@data-parity-hash')->item(0)?->nodeValue;

    expect($hash)->toBe($response->headers->get('X-LEA-Parity-Hash'));
});

it('shows the uncertainty marker with its reason on every item of the no-sources fixture', function (): void {
    $user = PwaFixtures::owner();
    $brief = BriefFixtures::import('no-sources-uncertain-only', PwaFixtures::organizationOf($user));
    $document = BriefFixtures::load('no-sources-uncertain-only');

    $response = actingAs($user)->get(route('briefs.show', $brief))->assertOk();
    $xpath = PwaFixtures::xpath($response->getContent());

    expect($xpath->query('//li[@data-brief-item]')->length)->toBe(count($document['items']))
        ->and($xpath->query('//li[@data-brief-item][.//*[@data-uncertainty]]')->length)->toBe(count($document['items']))
        ->and($xpath->query('//*[@data-source-ref]')->length)->toBe(0);

    foreach ($document['items'] as $item) {
        $response->assertSee($item['uncertainty']['reason']);
    }

    $response->assertSee(__('brief.uncertainty', [], 'en'));
});

it('renders the placeholder brief as nothing yet, with version and generated_at still visible', function (): void {
    $user = PwaFixtures::owner();
    $brief = PwaFixtures::placeholder(PwaFixtures::organizationOf($user));

    $response = actingAs($user)->get(route('briefs.show', $brief))->assertOk();

    $response->assertSee('Nothing yet')
        ->assertSee('Placeholder')
        ->assertSee('data-brief-version="1"', false)
        ->assertSee('data-brief-generated-at="2026-10-09T12:00:00+02:00"', false)
        ->assertSee(__('brief.items', [], 'sv').' (0)')
        ->assertDontSee('data-brief-item', false);

    expect(PwaFixtures::aiOnPhoneClaims($response->getContent()))->toBe([]);
});

it('renders an empty period as nothing new rather than as an error', function (): void {
    $user = PwaFixtures::owner();
    $brief = BriefFixtures::import('empty-period', PwaFixtures::organizationOf($user));

    actingAs($user)->get(route('briefs.show', $brief))->assertOk()
        ->assertSee(__('brief.nothing_new_title', [], 'sv'))
        ->assertSee(__('brief.items', [], 'sv').' (0)')
        ->assertDontSee('Nothing yet');
});

it('renders every fixture without error, each item carrying a source or marker', function (string $fixture): void {
    $user = PwaFixtures::owner();
    $brief = BriefFixtures::import($fixture, PwaFixtures::organizationOf($user));
    $document = BriefFixtures::load($fixture);

    $response = actingAs($user)->get(route('briefs.show', $brief))->assertOk();
    $xpath = PwaFixtures::xpath($response->getContent());

    expect($xpath->query('//li[@data-brief-item]')->length)->toBe(count($document['items']))
        ->and($xpath->query('//li[@data-brief-item][not(.//*[@data-source-ref]) and not(.//*[@data-uncertainty])]')->length)->toBe(0)
        ->and(PwaFixtures::aiOnPhoneClaims($response->getContent()))->toBe([]);
})->with(BriefFixtures::dataset());

it('opens the newest version when given the document id, as the mail and the menu bar link to it', function (): void {
    $user = PwaFixtures::owner();
    $organization = PwaFixtures::organizationOf($user);
    $first = BriefFixtures::import('canonical', $organization);

    $second = BriefFixtures::load('canonical');
    $second['version'] = 2;
    $second['generated_at'] = '2026-10-09T06:30:00+02:00';
    $second['idempotency_key'] = (string) Str::ulid();
    $second['content_hash'] = BriefContentHash::compute($second);
    $secondRow = app(BriefDocumentMapper::class)->import($second, $organization, Provenance::job('Test', ProvenanceOrigin::Import));

    actingAs($user)->get("/briefs/{$first->document_id}")->assertOk()
        ->assertSee('data-brief-version="2"', false)
        ->assertSee('data-brief-id="'.$secondRow->id.'"', false);

    actingAs($user)->get(route('briefs.show', $first))->assertOk()
        ->assertSee('data-brief-version="1"', false);

    actingAs($user)->get('/briefs')->assertOk()->assertSeeInOrder(['data-brief-version="2"', 'data-brief-version="1"'], false);
});

it('forbids a member of another organization and hides their versions from the list', function (): void {
    $user = PwaFixtures::owner();
    $foreign = BriefFixtures::import('canonical', Organization::factory()->create());

    actingAs($user)->get(route('briefs.show', $foreign))->assertForbidden();
    actingAs($user)->get(route('briefs.parity', $foreign))->assertForbidden();
    actingAs($user)->get('/briefs')->assertOk()->assertDontSee($foreign->document_id);
});

it('returns 404 for an unknown brief', function (): void {
    actingAs(PwaFixtures::owner())->get('/briefs/01M4FJ7GY0PZH6YA3D9574XN5K')->assertNotFound();
});

it('lists nothing for a user without a membership', function (): void {
    $user = User::factory()->create();
    BriefFixtures::import('canonical');

    actingAs($user)->get('/briefs')->assertOk()->assertSee('No briefs yet');
    actingAs($user)->get('/home')->assertOk()->assertSee('No brief yet');
});
