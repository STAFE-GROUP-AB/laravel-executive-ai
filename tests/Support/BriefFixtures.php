<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Briefs\BriefDocumentMapper;
use App\Models\Brief;
use App\Models\Organization;
use App\Support\Data\Provenance;
use App\Support\Data\ProvenanceOrigin;

/**
 * The frozen S0-18 brief fixture pack as test input. Names are the file
 * basenames without `brief-` and `.json`, so a dataset failure names the case.
 */
final class BriefFixtures
{
    public const string CANONICAL = 'canonical';

    /** @return array<string, string> name => absolute path */
    public static function all(): array
    {
        // Resolved while Pest collects datasets, before the application boots: no base_path().
        $files = glob(dirname(__DIR__, 2).'/packaging/fixtures/briefs/brief-*.json') ?: [];
        $fixtures = [];

        foreach ($files as $file) {
            $fixtures[substr(basename($file, '.json'), strlen('brief-'))] = $file;
        }

        ksort($fixtures);

        return $fixtures;
    }

    /** @return array<string, array{0: string}> Pest dataset: name => [name] */
    public static function dataset(): array
    {
        $dataset = [];

        foreach (array_keys(self::all()) as $name) {
            $dataset[$name] = [$name];
        }

        return $dataset;
    }

    public static function path(string $name): string
    {
        return self::all()[$name] ?? throw new \InvalidArgumentException("Unknown brief fixture `{$name}`");
    }

    /** @return array<string, mixed> */
    public static function load(string $name): array
    {
        $document = json_decode((string) file_get_contents(self::path($name)), true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($document)) {
            throw new \RuntimeException("Fixture `{$name}` is not a JSON object");
        }

        return $document;
    }

    public static function import(string $name, ?Organization $organization = null): Brief
    {
        $organization ??= Organization::factory()->create();

        $brief = app(BriefDocumentMapper::class)->import(
            self::load($name),
            $organization,
            Provenance::job('FixtureImport', ProvenanceOrigin::Import),
        );

        return $brief->fresh() ?? $brief;
    }
}
