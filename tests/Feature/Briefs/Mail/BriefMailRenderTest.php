<?php

declare(strict_types=1);

use App\Briefs\Render\BriefMailRenderer;
use App\Briefs\Render\InvalidBriefRenderInputException;
use App\Briefs\Render\RenderedBrief;
use App\Support\Json\Jcs;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\URL;
use Tests\Support\BriefFixtures;

/*
 * S1-03 acceptance: the renderer takes every frozen fixture (S0-18) to an
 * HTML and a plaintext part, and every item shows its source references or
 * its explicit uncertainty marker (§4.2). Blueprint §1.3, §5.5, §16 step 3.
 */

uses(DatabaseTransactions::class);

beforeEach(function (): void {
    URL::forceRootUrl('http://localhost:8000');
});

function renderFixture(string $name): RenderedBrief
{
    return app(BriefMailRenderer::class)->render(BriefFixtures::load($name));
}

/** The plaintext part soft-wraps at 76 columns; compare it with whitespace collapsed. */
function flattenText(string $text): string
{
    return preg_replace('/\s+/u', ' ', $text) ?? $text;
}

it('renders every fixture to both mail parts with the brief subject', function (string $name): void {
    $document = BriefFixtures::load($name);
    $rendered = renderFixture($name);

    expect($rendered->subject)->toBe($document['subject'])
        ->and($rendered->html)->toContain('<!DOCTYPE html>')
        ->and($rendered->html)->toContain(e($document['subject']))
        ->and(flattenText($rendered->text))->toContain($document['subject'])
        ->and($rendered->text)->toEndWith("\n")
        ->and($rendered->text)->not->toContain('<')
        ->and($rendered->text)->not->toContain('&quot;')
        ->and($rendered->text)->not->toContain('&amp;')
        ->and($rendered->contentHash())->toBe($document['content_hash'])
        ->and($rendered->version())->toBe($document['version']);
})->with(BriefFixtures::dataset());

it('prints every item in order with its title and whole body in both parts', function (string $name): void {
    $document = BriefFixtures::load($name);
    $rendered = renderFixture($name);
    $flatText = flattenText($rendered->text);

    // Items start after the summary (which may quote an item title) in both parts.
    $htmlCursor = (int) strpos($rendered->html, '<h2 ');
    $textCursor = (int) strpos($flatText, __('brief.items', [], $rendered->locale).' (');

    foreach ($document['items'] as $item) {
        $htmlPosition = strpos($rendered->html, '. '.e($item['title']).'</h3>', $htmlCursor);
        $textPosition = strpos($flatText, '] '.$item['title'], $textCursor);

        expect($htmlPosition)->not->toBeFalse("Item {$item['id']} title missing or out of order in the HTML part")
            ->and($textPosition)->not->toBeFalse("Item {$item['id']} title missing or out of order in the text part")
            ->and($rendered->html)->toContain(e($item['body']))
            ->and($flatText)->toContain(flattenText($item['body']));

        $htmlCursor = (int) $htmlPosition + 1;
        $textCursor = (int) $textPosition + 1;
    }

    expect(substr_count($rendered->html, '<h3 '))->toBe(count($document['items']));
})->with(BriefFixtures::dataset());

it('shows source references or the uncertainty marker for every item, never neither', function (string $name): void {
    $document = BriefFixtures::load($name);
    $rendered = renderFixture($name);

    expect($rendered->parity['items'])->toHaveCount(count($document['items']));

    foreach ($rendered->parity['items'] as $item) {
        $hasSources = $item['source_refs'] !== [];
        $hasUncertainty = $item['uncertainty'] !== null;

        expect($hasSources || $hasUncertainty)->toBeTrue("Item {$item['id']} is a bare claim");

        foreach ($item['source_refs'] as $source) {
            expect($rendered->html)->toContain(e($source['uri']))
                ->and($rendered->text)->toContain($source['uri']);

            if ($source['account_label'] !== null) {
                expect($rendered->html)->toContain(e($source['account_label']))
                    ->and(flattenText($rendered->text))->toContain($source['account_label']);
            }
        }

        if ($hasUncertainty) {
            expect($rendered->html)->toContain(e($item['uncertainty']['reason']))
                ->and($rendered->text)->toContain(__('brief.uncertainty', [], $rendered->locale));
        }

        if ($item['due_at'] === null) {
            continue;
        }

        expect($hasSources)->toBeTrue("Item {$item['id']} has a due_at without a source (§4.2)");
    }
})->with(BriefFixtures::dataset());

it('prints a deadline only when the document has one', function (): void {
    $rendered = renderFixture('no-sources-uncertain-only');

    expect($rendered->html)->not->toContain(__('brief.due', [], 'en').':')
        ->and($rendered->text)->not->toContain(__('brief.due', [], 'en').':')
        ->and(substr_count($rendered->html, 'Uncertain</strong>'))->toBe(3);
});

it('shows both sources and the uncertainty marker on a conflicting-sources item', function (): void {
    $rendered = renderFixture('conflicting-sources');
    $item = $rendered->parity['items'][0];

    expect($item['source_refs'])->toHaveCount(2)
        ->and($item['uncertainty'])->not->toBeNull()
        ->and($item['due_at'])->toBeNull()
        ->and($rendered->html)->toContain('andreas@example.it (iCloud Mail)')
        ->and($rendered->html)->toContain('lxai://crm/projects/01M2J18Y00KX8VD12RT0E08F2E')
        ->and($rendered->html)->toContain(e($item['uncertainty']['reason']))
        ->and($rendered->html)->toContain('confidence 50%')
        ->and($rendered->text)->toContain('Uncertain (confidence 50%)');
});

it('renders the empty period as nothing new, not as an error', function (): void {
    $rendered = renderFixture('empty-period');

    expect($rendered->parity['items'])->toBe([])
        ->and($rendered->html)->toContain('Inget nytt')
        ->and($rendered->html)->toContain('Inga nya händelser under perioden.')
        ->and($rendered->html)->not->toContain('<h3 ')
        ->and($rendered->text)->toContain("Inget nytt\nInga nya händelser under perioden.");
});

it('round-trips non-ASCII content in both parts', function (string $name, array $samples): void {
    $rendered = renderFixture($name);

    foreach ($samples as $sample) {
        expect($rendered->html)->toContain($sample)
            ->and(flattenText($rendered->text))->toContain($sample);
    }
})->with([
    'sv' => ['multilingual-sv', ['Örjan Sjöström', 'Åkersberga', 'Mölndal']],
    'en' => ['multilingual-en', ['Zoë Brontë-Çelik', 'São Paulo', 'naïve', 'façade', '’']],
    'it' => ['multilingual-it', ['Niccolò Perù', 'venerdì', 'città']],
    'de' => ['multilingual-de', ['Jörg Müßig', 'Größe', 'Straße', '„Rückruf Weber“']],
]);

it('uses the labels of the brief language', function (string $name, string $locale, string $itemsHeading): void {
    $rendered = renderFixture($name);

    expect($rendered->locale)->toBe($locale)
        ->and($rendered->html)->toContain('<html lang="'.$locale.'"')
        ->and($rendered->html)->toContain($itemsHeading);
})->with([
    ['canonical', 'sv', 'Punkter (5)'],
    ['multilingual-en', 'en', 'Items (3)'],
    ['multilingual-it', 'it', 'Punti (3)'],
    ['multilingual-de', 'de', 'Punkte (3)'],
]);

it('exports a parity payload with a fixed shape and the document order', function (): void {
    $document = BriefFixtures::load('canonical');
    $rendered = renderFixture('canonical');
    $payload = $rendered->parity;

    expect(array_keys($payload))->toBe([
        'schema_version', 'id', 'version', 'generated_at', 'period_start', 'period_end',
        'language', 'subject', 'summary', 'privacy_mode', 'content_hash', 'items',
    ])
        ->and($payload['id'])->toBe($document['id'])
        ->and($payload['version'])->toBe(1)
        ->and($payload['generated_at'])->toBe('2026-10-09T05:30:00+02:00')
        ->and($payload['privacy_mode'])->toBe('LOCAL_ONLY')
        ->and(array_column($payload['items'], 'id'))->toBe(array_column($document['items'], 'id'))
        ->and($rendered->parityJson())->toBe(Jcs::canonicalize($payload))
        ->and($rendered->parityHash())->toStartWith('sha256:');

    foreach ($payload['items'] as $item) {
        expect(array_keys($item))->toBe(['id', 'kind', 'priority', 'title', 'body', 'due_at', 'source_refs', 'uncertainty', 'action_ids']);

        foreach ($item['source_refs'] as $source) {
            expect(array_keys($source))->toBe(['id', 'type', 'account_label', 'title', 'uri']);
        }
    }

    expect($payload['items'][0]['source_refs'][0]['account_label'])->toBe('andreas@example.se (Google Workspace)')
        ->and($payload['items'][4]['source_refs'])->toBe([])
        ->and($payload['items'][4]['uncertainty'])->toBe([
            'reason' => 'Slutsats dragen från CI-status och granskningskö; ingen källa anger ett nytt releasedatum.',
            'confidence' => 0.4,
        ])
        ->and($payload['items'][4]['due_at'])->toBeNull();
});

it('refuses a document from a newer schema major instead of rendering a partial brief', function (): void {
    $document = BriefFixtures::load('canonical');
    $document['schema_version'] = '2.0.0';

    expect(fn () => app(BriefMailRenderer::class)->render($document))
        ->toThrow(InvalidBriefRenderInputException::class, 'update required');
});

it('renders a document from a newer schema minor and ignores unknown keys', function (): void {
    $document = BriefFixtures::load('canonical');
    $document['schema_version'] = '1.4.0';
    $document['items'][0]['owner_note'] = 'unknown key';

    expect(app(BriefMailRenderer::class)->render($document)->html)->not->toContain('unknown key');
});

it('refuses a bare claim', function (): void {
    $document = BriefFixtures::load('canonical');
    unset($document['items'][0]['source_refs']);

    expect(fn () => app(BriefMailRenderer::class)->render($document))
        ->toThrow(InvalidBriefRenderInputException::class, '§4.2');
});

it('renders a stored brief identically to its fixture', function (): void {
    $brief = BriefFixtures::import('canonical');

    $fromModel = app(BriefMailRenderer::class)->renderBrief($brief);
    $fromFixture = renderFixture('canonical');

    expect($fromModel->html)->toBe($fromFixture->html)
        ->and($fromModel->text)->toBe($fromFixture->text)
        ->and($fromModel->parityJson())->toBe($fromFixture->parityJson());
});
