<?php

declare(strict_types=1);

namespace Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Committed snapshots under tests/__snapshots__/. A missing snapshot is
 * written on first run and the test fails, so a new snapshot is always
 * reviewed in the diff; `UPDATE_SNAPSHOTS=1 vendor/bin/pest` rewrites
 * existing ones deliberately.
 */
final class Snapshot
{
    public static function directory(): string
    {
        return dirname(__DIR__).'/__snapshots__';
    }

    public static function path(string $name): string
    {
        return self::directory().'/'.$name;
    }

    public static function assertMatches(string $name, string $actual): void
    {
        $path = self::path($name);
        $update = getenv('UPDATE_SNAPSHOTS') === '1';

        if (! is_file($path) || $update) {
            if (! is_dir(self::directory())) {
                mkdir(self::directory(), 0775, true);
            }

            file_put_contents($path, $actual);

            if (! $update) {
                Assert::fail("Snapshot `{$name}` did not exist and was written to {$path}; review it and re-run.");
            }
        }

        $expected = (string) file_get_contents($path);

        Assert::assertSame($expected, $actual, "Rendered output differs from snapshot `{$name}`. If the change is intended, run UPDATE_SNAPSHOTS=1 vendor/bin/pest and commit the new snapshot.");
    }
}
