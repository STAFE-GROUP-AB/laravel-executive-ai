<?php

declare(strict_types=1);

use App\Briefs\Render\BriefMailRenderer;
use Illuminate\Support\Facades\URL;
use Tests\Support\BriefFixtures;
use Tests\Support\Snapshot;

/*
 * Presentation snapshots of the canonical fixture (brief schema §6 "Snapshot
 * tests then guard each surface's presentation separately"). The committed
 * HTML is also the UI evidence attached to the S1-03 pull request.
 */

beforeEach(function (): void {
    URL::forceRootUrl('http://localhost:8000');
    config(['app.name' => 'Laravel Executive AI']);
});

it('matches the committed plaintext snapshot of the canonical fixture', function (): void {
    $rendered = app(BriefMailRenderer::class)->render(BriefFixtures::load(BriefFixtures::CANONICAL));

    Snapshot::assertMatches('brief-canonical.txt', $rendered->text);
});

it('matches the committed html snapshot of the canonical fixture', function (): void {
    $rendered = app(BriefMailRenderer::class)->render(BriefFixtures::load(BriefFixtures::CANONICAL));

    Snapshot::assertMatches('brief-canonical.html', $rendered->html);
});

it('matches the committed parity payload of the canonical fixture', function (): void {
    $rendered = app(BriefMailRenderer::class)->render(BriefFixtures::load(BriefFixtures::CANONICAL));

    Snapshot::assertMatches('brief-canonical.parity.json', $rendered->parityJson()."\n");
});
