<?php

declare(strict_types=1);

use App\Briefs\BriefDocumentMapper;
use App\Briefs\Render\BriefMailRenderer;
use App\Briefs\Render\BriefPage;
use App\Briefs\Render\BriefParityPayload;
use App\Support\Json\Jcs;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\URL;
use Tests\Support\BriefFixtures;
use Tests\Support\MenuBarParityExport;
use Tests\Support\PwaFixtures;
use Tests\Support\Snapshot;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

/*
 * Tri-surface brief parity (S1-10, blueprint §1.3, §16 step 3, §17;
 * docs/architecture/brief-parity.md §3). One run, every fixture, three
 * renderers: the Blade mail, the PWA page (its `BriefPage` and the owner
 * route `GET /briefs/{brief}/parity.json`) and the SwiftUI menu bar, whose
 * export `BriefParitySnapshotTests` writes to LXAI_SWIFT_PARITY_EXPORT_DIR.
 * The comparison is the RFC 8785 bytes of the semantic payload, never the
 * markup, so a layout change cannot break the gate and a content change
 * cannot pass it.
 */

uses(DatabaseTransactions::class);

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse(PwaFixtures::FIXTURE_NOW));
    URL::forceRootUrl('http://localhost:8000');
});

/**
 * Imports one fixture as the owner's brief and renders the two PHP surfaces
 * from the stored row, the way production does (mail from the delivery job,
 * page from the controller), not from the fixture file.
 *
 * @return array{
 *     document: array<string, mixed>,
 *     mail: string,
 *     page: string,
 *     endpoint: string,
 *     endpoint_hash: ?string,
 *     page_hash: string,
 *     dom_hash: ?string
 * }
 */
function renderBriefParitySurfaces(string $fixture): array
{
    $user = PwaFixtures::owner();
    $brief = BriefFixtures::import($fixture, PwaFixtures::organizationOf($user));

    $mail = app(BriefMailRenderer::class)->renderBrief($brief);
    $page = BriefPage::fromBrief($brief, app(BriefDocumentMapper::class), CarbonImmutable::now());
    $endpoint = actingAs($user)->get(route('briefs.parity', $brief))->assertOk();
    $html = (string) actingAs($user)->get(route('briefs.show', $brief))->assertOk()->getContent();

    return [
        'document' => BriefFixtures::load($fixture),
        'mail' => $mail->parityJson()."\n",
        'page' => $page->parityJson()."\n",
        'endpoint' => $endpoint->getContent()."\n",
        'endpoint_hash' => $endpoint->headers->get('X-LEA-Parity-Hash'),
        'page_hash' => $page->parityHash(),
        'dom_hash' => PwaFixtures::xpath($html)->query('//article[@data-brief]/@data-parity-hash')->item(0)?->nodeValue,
    ];
}

/** @return array{version: int, generated_at: string, content_hash: string} */
function briefParityIdentity(string $export): array
{
    $payload = json_decode(rtrim($export, "\n"), true, 512, JSON_THROW_ON_ERROR);

    return [
        'version' => $payload['version'],
        'generated_at' => $payload['generated_at'],
        'content_hash' => $payload['content_hash'],
    ];
}

it('renders mail, pwa page and pwa endpoint to byte-identical JCS equal to the fixture projection', function (string $fixture): void {
    $run = renderBriefParitySurfaces($fixture);
    $projection = BriefParityPayload::canonicalJson(BriefParityPayload::fromDocument($run['document']))."\n";

    expect($run['mail'])->toBe($projection, "mail export of `{$fixture}` differs from BriefParityPayload::fromDocument(fixture)")
        ->and($run['page'])->toBe($run['mail'], "pwa BriefPage export of `{$fixture}` differs from the mail export")
        ->and($run['endpoint'])->toBe($run['mail'], "GET /briefs/{brief}/parity.json of `{$fixture}` differs from the mail export");
})->with(BriefFixtures::dataset());

it('keeps version, generated_at and content_hash identical across mail, pwa and the fixture', function (string $fixture): void {
    $run = renderBriefParitySurfaces($fixture);
    $fixtureIdentity = [
        'version' => (int) $run['document']['version'],
        'generated_at' => (string) $run['document']['generated_at'],
        'content_hash' => (string) $run['document']['content_hash'],
    ];

    expect(briefParityIdentity($run['mail']))->toBe($fixtureIdentity, "mail identity of `{$fixture}`")
        ->and(briefParityIdentity($run['page']))->toBe($fixtureIdentity, "pwa page identity of `{$fixture}`")
        ->and(briefParityIdentity($run['endpoint']))->toBe($fixtureIdentity, "pwa endpoint identity of `{$fixture}`");
})->with(BriefFixtures::dataset());

it('stamps the pwa article with the sha256 of the payload it exported', function (string $fixture): void {
    $run = renderBriefParitySurfaces($fixture);
    $expected = Jcs::sha256(json_decode(rtrim($run['page'], "\n"), true, 512, JSON_THROW_ON_ERROR));

    expect($run['dom_hash'])->toBe($expected, "data-parity-hash of `{$fixture}` is not the sha256 of the export")
        ->and($run['page_hash'])->toBe($expected)
        ->and($run['endpoint_hash'])->toBe($expected);
})->with(BriefFixtures::dataset());

it('matches the committed parity snapshot for the canonical fixture', function (): void {
    $run = renderBriefParitySurfaces(BriefFixtures::CANONICAL);

    Snapshot::assertMatches('brief-canonical.parity.json', $run['mail']);
    Snapshot::assertMatches('brief-canonical.parity.json', $run['page']);
    Snapshot::assertMatches('brief-canonical.parity.json', $run['endpoint']);
});

it('names the menu bar oracle of this lane', function (): void {
    if (! MenuBarParityExport::available()) {
        expect(getenv(MenuBarParityExport::ENV))->toBeFalse()
            ->and(is_file(Snapshot::path('brief-canonical.parity.json')))->toBeTrue()
            ->and(MenuBarParityExport::oracle())->toBe(MenuBarParityExport::ORACLE_COMMITTED_SNAPSHOT);

        fwrite(STDERR, PHP_EOL.MenuBarParityExport::NOT_AVAILABLE.': the menu bar surface is compared through the committed snapshot '
            .'(canonical fixture only); the `parity` CI job compares every fixture against the Swift export.'.PHP_EOL);

        return;
    }

    expect(MenuBarParityExport::oracle())->toBe(MenuBarParityExport::ORACLE_SWIFT_EXPORT)
        ->and(MenuBarParityExport::exportedFixtures())->toBe(array_keys(BriefFixtures::all()), 'the Swift test did not export exactly the fixture pack');
});

it('is byte-identical to the menu bar export, with the same version and generated_at', function (string $fixture, string $oracle): void {
    $run = renderBriefParitySurfaces($fixture);
    $menuBar = MenuBarParityExport::read($fixture, $oracle);

    expect($menuBar)->toBe($run['mail'], "menu bar ({$oracle}) export of `{$fixture}` differs from the mail export")
        ->and($menuBar)->toBe($run['page'], "menu bar ({$oracle}) export of `{$fixture}` differs from the pwa page export")
        ->and($menuBar)->toBe($run['endpoint'], "menu bar ({$oracle}) export of `{$fixture}` differs from GET /briefs/{brief}/parity.json");

    $identity = briefParityIdentity($menuBar);

    expect($identity['version'])->toBe((int) $run['document']['version'])
        ->and($identity['generated_at'])->toBe((string) $run['document']['generated_at'])
        ->and($identity['content_hash'])->toBe((string) $run['document']['content_hash'])
        ->and($identity)->toBe(briefParityIdentity($run['mail']))
        ->and($identity)->toBe(briefParityIdentity($run['page']));
})->with(MenuBarParityExport::dataset());
