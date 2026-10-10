<?php

declare(strict_types=1);

use App\Briefs\Render\BriefMailRenderer;
use Tests\Support\BriefFixtures;

/*
 * Basic accessibility of the HTML part (§1.3 "tillgänglig", design-system §6):
 * document language, presentation tables, alt text, heading order, a
 * title, and the light colour scheme pinned so clients do not auto-invert.
 */

it('passes the basic accessibility checks', function (string $name): void {
    $document = BriefFixtures::load($name);
    $html = app(BriefMailRenderer::class)->render($document)->html;

    $dom = new DOMDocument;
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NONET);
    libxml_clear_errors();

    expect($dom->documentElement?->tagName)->toBe('html')
        ->and($dom->documentElement?->getAttribute('lang'))->toBe(substr($document['language'], 0, 2))
        ->and($dom->documentElement?->getAttribute('dir'))->toBe('ltr')
        ->and($dom->getElementsByTagName('title')->item(0)?->textContent)->toBe($document['subject']);

    foreach ($dom->getElementsByTagName('table') as $table) {
        expect($table->getAttribute('role'))->toBe('presentation');
    }

    foreach ($dom->getElementsByTagName('img') as $img) {
        expect($img->hasAttribute('alt'))->toBeTrue();
    }

    $headings = [];

    foreach ((new DOMXPath($dom))->query('//h1|//h2|//h3|//h4|//h5|//h6') ?: [] as $heading) {
        $headings[] = (int) substr($heading->nodeName, 1);
    }

    expect($headings[0] ?? null)->toBe(1)
        ->and(array_count_values($headings)[1])->toBe(1);

    foreach ($headings as $index => $level) {
        if ($index === 0) {
            continue;
        }

        expect($level)->toBeLessThanOrEqual($headings[$index - 1] + 1, 'Heading levels must not skip');
    }

    $metas = [];

    foreach ($dom->getElementsByTagName('meta') as $meta) {
        $metas[$meta->getAttribute('name')] = $meta->getAttribute('content');
    }

    expect($metas['color-scheme'] ?? null)->toBe('light')
        ->and($metas['supported-color-schemes'] ?? null)->toBe('light')
        ->and($metas['viewport'] ?? null)->toContain('width=device-width')
        ->and($html)->toContain('<meta charset="utf-8">')
        ->and($html)->toContain('width="600"');

    foreach ($dom->getElementsByTagName('a') as $anchor) {
        expect(trim($anchor->textContent))->not->toBe('', 'Every link needs visible text');
    }
})->with(BriefFixtures::dataset());
