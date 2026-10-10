<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * The third surface of the tri-surface brief parity gate (S1-10): the JCS
 * exports the SwiftUI menu bar writes from `BriefParitySnapshotTests`, one
 * `brief-<fixture>.parity.json` per fixture, into the directory named by
 * LXAI_SWIFT_PARITY_EXPORT_DIR.
 *
 * Which oracle a lane has is decided here, once, while Pest collects
 * datasets, and is spelled out in the dataset key so a run always says what
 * it compared against: the `parity` CI job (macOS, Swift test first) has the
 * Swift export for every fixture; the ubuntu `php` lane has no Swift and
 * falls back to the committed snapshot for the canonical fixture — the same
 * bytes the `swift` lane proves the Swift port produces.
 */
final class MenuBarParityExport
{
    public const string ENV = 'LXAI_SWIFT_PARITY_EXPORT_DIR';

    public const string ORACLE_SWIFT_EXPORT = 'swift export';

    public const string ORACLE_COMMITTED_SNAPSHOT = 'committed snapshot';

    public const string NOT_AVAILABLE = 'Swift export not available in this lane';

    public static function directory(): ?string
    {
        $directory = getenv(self::ENV);

        if (! is_string($directory) || trim($directory) === '') {
            return null;
        }

        return rtrim($directory, '/');
    }

    public static function available(): bool
    {
        return self::directory() !== null;
    }

    public static function oracle(): string
    {
        return self::available() ? self::ORACLE_SWIFT_EXPORT : self::ORACLE_COMMITTED_SNAPSHOT;
    }

    /** The file BriefParitySnapshotTests writes for a fixture: the committed snapshot's naming. */
    public static function fileName(string $fixture): string
    {
        return "brief-{$fixture}.parity.json";
    }

    public static function path(string $fixture): string
    {
        return self::directory().'/'.self::fileName($fixture);
    }

    /**
     * Pest dataset: every fixture against the Swift export when the lane has
     * one, otherwise only the canonical fixture against the committed
     * snapshot. The key names the oracle so the test output does.
     *
     * @return array<string, array{0: string, 1: string}> label => [fixture, oracle]
     */
    public static function dataset(): array
    {
        if (! self::available()) {
            $label = BriefFixtures::CANONICAL.' · oracle: '.self::ORACLE_COMMITTED_SNAPSHOT.' ('.self::NOT_AVAILABLE.')';

            return [$label => [BriefFixtures::CANONICAL, self::ORACLE_COMMITTED_SNAPSHOT]];
        }

        $dataset = [];

        foreach (array_keys(BriefFixtures::all()) as $fixture) {
            $dataset["{$fixture} · oracle: ".self::ORACLE_SWIFT_EXPORT] = [$fixture, self::ORACLE_SWIFT_EXPORT];
        }

        return $dataset;
    }

    /** The menu bar export bytes for a fixture: payload JCS plus one trailing newline. */
    public static function read(string $fixture, string $oracle): string
    {
        if ($oracle === self::ORACLE_COMMITTED_SNAPSHOT) {
            if ($fixture !== BriefFixtures::CANONICAL) {
                throw new \InvalidArgumentException("Only the canonical fixture has a committed snapshot; `{$fixture}` needs the Swift export");
            }

            return (string) file_get_contents(Snapshot::path('brief-canonical.parity.json'));
        }

        $path = self::path($fixture);

        if (! is_file($path)) {
            throw new \RuntimeException(
                "Swift export for `{$fixture}` missing at {$path}. Run the Swift side first: "
                .'xcodebuild test -only-testing:LaravelExecutiveAITests/BriefParitySnapshotTests '
                .'(writes <repo>/build/parity), then this test with '.self::ENV.'=<repo>/build/parity.'
            );
        }

        return (string) file_get_contents($path);
    }

    /**
     * The fixtures the Swift test exported, by name, so the harness can prove
     * the two sides walked the same fixture pack.
     *
     * @return list<string>
     */
    public static function exportedFixtures(): array
    {
        $directory = self::directory();

        if ($directory === null) {
            return [];
        }

        $names = [];

        foreach (glob($directory.'/brief-*.parity.json') ?: [] as $file) {
            $names[] = substr(basename($file, '.parity.json'), strlen('brief-'));
        }

        sort($names);

        return $names;
    }
}
