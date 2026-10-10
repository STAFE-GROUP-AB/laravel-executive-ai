<?php

declare(strict_types=1);

use App\Briefs\BriefDocumentMapper;
use App\Briefs\Render\BriefPage;
use App\Briefs\Render\BriefParityPayload;
use App\Briefs\Render\BriefTime;
use App\Briefs\Render\BriefViewModel;
use App\Models\Organization;
use App\Support\Health\BriefState;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\BriefFixtures;
use Tests\Support\PwaFixtures;
use Tests\Support\Snapshot;

uses(DatabaseTransactions::class);

it('is built from the parity payload and the mail view model, never from its own projection', function (): void {
    $brief = BriefFixtures::import('canonical');
    $document = BriefFixtures::load('canonical');
    $now = CarbonImmutable::parse(PwaFixtures::FIXTURE_NOW);

    $page = BriefPage::fromBrief($brief, app(BriefDocumentMapper::class), $now);

    expect($page->payload)->toBe(BriefParityPayload::fromDocument($document))
        ->and($page->parityJson())->toBe(rtrim((string) file_get_contents(Snapshot::path('brief-canonical.parity.json'))))
        ->and($page->view)->toBe(BriefViewModel::build($page->payload, 'sv'))
        ->and($page->locale)->toBe('sv')
        ->and($page->rowId)->toBe($brief->id)
        ->and($page->stale)->toBeFalse()
        ->and($page->state())->toBe(BriefState::Fresh)
        ->and($page->placeholder)->toBeFalse()
        ->and($page->deliveryState)->toBe('sent')
        ->and($page->provider)->toBe('ollama')
        ->and($page->staleAfter)->toBe('2026-10-10T05:30:00+02:00')
        ->and(array_column($page->items(), 'id'))->toBe(array_column($document['items'], 'id'))
        ->and($page->items()[0]['sources'][0]['account_label'])->toBe('andreas@example.se (Google Workspace)')
        ->and($page->items()[4]['uncertainty']['confidence_label'])->toBe(__('brief.confidence', ['percent' => 40], 'sv'));
});

it('is stale once stale_after has passed', function (): void {
    $brief = BriefFixtures::import('canonical');

    $page = BriefPage::fromBrief($brief, app(BriefDocumentMapper::class), CarbonImmutable::parse('2026-10-10T05:30:00+02:00'));

    expect($page->stale)->toBeTrue()->and($page->state())->toBe(BriefState::Stale);
});

it('tells the install placeholder apart from a real empty brief', function (): void {
    $mapper = app(BriefDocumentMapper::class);
    $now = CarbonImmutable::parse(PwaFixtures::FIXTURE_NOW);

    $placeholder = BriefPage::fromBrief(PwaFixtures::placeholder(Organization::factory()->create()), $mapper, $now);
    $emptyPeriod = BriefPage::fromBrief(BriefFixtures::import('empty-period'), $mapper, $now);

    expect($placeholder->placeholder)->toBeTrue()
        ->and($placeholder->hasItems())->toBeFalse()
        ->and($emptyPeriod->placeholder)->toBeFalse()
        ->and($emptyPeriod->hasItems())->toBeFalse()
        ->and($emptyPeriod->provider)->toBe('none');
});

it('renders stored UTC instants back in the brief offset exactly like the mapper', function (): void {
    $utc = CarbonImmutable::parse('2026-10-09T03:30:00+00:00');

    expect(BriefTime::rfc3339($utc, '+02:00'))->toBe('2026-10-09T05:30:00+02:00')
        ->and(BriefTime::rfc3339($utc, 'Z'))->toBe('2026-10-09T03:30:00Z')
        ->and(BriefTime::rfc3339($utc, '-05:00'))->toBe('2026-10-08T22:30:00-05:00');
});
