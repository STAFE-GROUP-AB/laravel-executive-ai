<?php

declare(strict_types=1);

use App\Briefs\Render\BriefMailRenderer;
use App\Briefs\Render\BriefMailStyles;
use App\Support\Design\MailTokens;
use Tests\Support\BriefFixtures;

/*
 * S1-03 acceptance "inline styles only, within the S0-24 mail-safe token
 * subset" (design-system.md §5). Two layers: the rendered HTML may only
 * carry style attributes from BriefMailStyles::all(), and the extras in
 * BriefMailStyles may only be composed from MailTokens values.
 */

function mailDom(string $html): DOMDocument
{
    $dom = new DOMDocument;
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NONET);
    libxml_clear_errors();

    return $dom;
}

/** @return array<int, DOMElement> */
function mailElements(DOMDocument $dom, string $tag = '*'): array
{
    return iterator_to_array($dom->getElementsByTagName($tag), false);
}

it('uses only allowlisted inline styles in the html part', function (string $name): void {
    $html = app(BriefMailRenderer::class)->render(BriefFixtures::load($name))->html;
    $allowed = array_values(BriefMailStyles::all());
    $seen = 0;

    foreach (mailElements(mailDom($html)) as $element) {
        if (! $element->hasAttribute('style')) {
            continue;
        }

        $seen++;
        $style = $element->getAttribute('style');

        expect(in_array($style, $allowed, true))->toBeTrue("<{$element->tagName}> carries a style outside BriefMailStyles::all(): {$style}");
    }

    expect($seen)->toBeGreaterThan(10);
})->with(BriefFixtures::dataset());

it('carries no script, stylesheet, style block, import, class or external asset', function (string $name): void {
    $html = app(BriefMailRenderer::class)->render(BriefFixtures::load($name))->html;
    $dom = mailDom($html);

    expect(mailElements($dom, 'script'))->toBe([])
        ->and(mailElements($dom, 'link'))->toBe([])
        ->and(mailElements($dom, 'style'))->toBe([])
        ->and(mailElements($dom, 'img'))->toBe([])
        ->and(mailElements($dom, 'iframe'))->toBe([])
        ->and(mailElements($dom, 'form'))->toBe([])
        ->and(stripos($html, '@import'))->toBeFalse()
        ->and(stripos($html, '@font-face'))->toBeFalse()
        ->and(stripos($html, 'url('))->toBeFalse()
        ->and(stripos($html, 'var(--'))->toBeFalse()
        ->and(stripos($html, 'box-shadow'))->toBeFalse();

    foreach (mailElements($dom) as $element) {
        if ($element->hasAttribute('style')) {
            // letter-spacing in em is part of the S0-24 h1/label tokens; every size is px.
            $sizes = preg_replace('/letter-spacing:[^;]*/', '', $element->getAttribute('style')) ?? '';
            expect(preg_match('/\d(rem|em)\b/', $sizes))->toBe(0, "<{$element->tagName}> uses rem/em sizes");
        }

        expect($element->hasAttribute('src'))->toBeFalse("<{$element->tagName}> has a src attribute")
            ->and($element->hasAttribute('class'))->toBeFalse("<{$element->tagName}> has a class attribute")
            ->and($element->hasAttribute('background'))->toBeFalse();

        foreach ($element->attributes ?? [] as $attribute) {
            expect(str_starts_with(strtolower($attribute->nodeName), 'on'))->toBeFalse("<{$element->tagName}> has an event handler attribute {$attribute->nodeName}");
        }
    }

    foreach (mailElements($dom, 'a') as $anchor) {
        $href = $anchor->getAttribute('href');

        expect(preg_match('#^https?://#', $href))->toBe(1, "Anchor href is not http(s): {$href}")
            ->and(str_starts_with($href, 'javascript:'))->toBeFalse();
    }
})->with(BriefFixtures::dataset());

it('composes the extra style roles only from MailTokens values', function (): void {
    $tokenColours = [];
    $tokenNumbers = [0, 1, 2];
    $tokenFonts = [MailTokens::FONT_SANS, MailTokens::FONT_MONO];

    foreach ((new ReflectionClass(MailTokens::class))->getConstants() as $value) {
        if (is_string($value) && str_starts_with($value, '#')) {
            $tokenColours[] = strtolower($value);
        }

        if (is_int($value)) {
            $tokenNumbers[] = $value;
        }
    }

    $structuralProperties = ['display', 'width', 'max-width', 'border-collapse', 'border-spacing', 'text-align', 'text-decoration', 'text-transform', 'font-weight', 'letter-spacing', 'box-sizing', '-webkit-text-size-adjust'];

    foreach (BriefMailStyles::extras() as $role => $style) {
        foreach (array_filter(array_map(trim(...), explode(';', $style))) as $declaration) {
            [$property, $value] = array_map(trim(...), explode(':', $declaration, 2));

            if ($property === 'font-family') {
                expect(in_array($value, $tokenFonts, true))->toBeTrue("{$role}: font-family is not a token stack");

                continue;
            }

            if (in_array($property, $structuralProperties, true)) {
                continue;
            }

            preg_match_all('/#[0-9a-fA-F]{3,8}\b/', $value, $colours);

            foreach ($colours[0] as $colour) {
                expect(in_array(strtolower($colour), $tokenColours, true))->toBeTrue("{$role}: colour {$colour} is not a MailTokens colour");
            }

            preg_match_all('/(\d+)px/', $value, $sizes);

            foreach ($sizes[1] as $size) {
                expect(in_array((int) $size, $tokenNumbers, true))->toBeTrue("{$role}: {$size}px is not a MailTokens size");
            }

            $stripped = preg_replace(['/#[0-9a-fA-F]{3,8}\b/', '/\d+px/', '/\b(solid|auto|inline-block|center|none|normal)\b/', '/\s+/', '/0/'], '', $value);

            expect($stripped)->toBe('', "{$role}: `{$property}: {$value}` contains a literal outside the token set");
        }
    }
});

it('never uses unescaped output in the html mail view', function (): void {
    $view = (string) file_get_contents(resource_path('views/mail/brief.blade.php'));

    expect($view)->not->toContain('{!!')
        ->and($view)->not->toContain('@php')
        ->and($view)->not->toContain('<script');
});
