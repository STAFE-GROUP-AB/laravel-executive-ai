<?php

declare(strict_types=1);

use App\Briefs\BriefContentHash;
use App\Support\Json\Jcs;

/*
 * The PHP JCS port must agree byte for byte with packaging/fixtures/lib/jcs.mjs,
 * which computed every content_hash in the frozen fixture pack (S0-13, S0-18).
 */

it('hashes brief-canonical.json to its frozen content_hash', function (): void {
    $document = json_decode((string) file_get_contents(base_path('packaging/fixtures/briefs/brief-canonical.json')), true, 512, JSON_THROW_ON_ERROR);

    expect(BriefContentHash::compute($document))
        ->toBe('sha256:558c32969727cb2a55714a4698183318c813445b7288011fc668d08cdb0d5a26')
        ->toBe($document['content_hash']);
});

it('reproduces the stored content_hash of every brief fixture', function (string $file): void {
    $document = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

    expect(BriefContentHash::matches($document))->toBeTrue();
})->with(fn () => glob(dirname(__DIR__, 3).'/packaging/fixtures/briefs/*.json') ?: []);

it('sorts members by UTF-16 code units, escapes control characters and leaves non-ASCII unescaped', function (): void {
    $lineSeparator = "\u{2028}";
    $emoji = "\u{1F600}";

    expect(Jcs::canonicalize(['b' => 1, 'a' => [true, null, "x/{$lineSeparator}{$emoji}\"\x01"], 'é' => [], 'Z' => new stdClass]))
        ->toBe('{"Z":{},"a":[true,null,"x/'.$lineSeparator.$emoji.'\"\\u0001"],"b":1,"é":[]}');
});

it('prints numbers the way ES6 Number::toString does', function (float|int $value, string $expected): void {
    expect(Jcs::canonicalize($value))->toBe($expected);
})->with([
    [0.4, '0.4'],
    [1.0, '1'],
    [100.0, '100'],
    [1e21, '1e+21'],
    [1e22, '1e+22'],
    [0.000001, '0.000001'],
    [0.0000001, '1e-7'],
    [123456789.125, '123456789.125'],
    [-0.5, '-0.5'],
    [1.5e-10, '1.5e-10'],
    [2 ** 60, '1152921504606846976'],
    [0.0, '0'],
    [42, '42'],
]);

it('refuses values JSON cannot carry', function (): void {
    expect(fn () => Jcs::canonicalize(NAN))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Jcs::canonicalize(fopen('php://memory', 'r')))->toThrow(InvalidArgumentException::class);
});
