<?php

declare(strict_types=1);

namespace Tests\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * The machine-readable record of one §16 step 8 run (S1-15): the six steps
 * in blueprint order with their outcome and duration, the brief identity
 * (`version`, `content_hash`) each surface reported, and the §13.1
 * assurances. Written to LXAI_SLICE_REPORT (default build/e2e/vertical-slice.json)
 * by the test, whatever the outcome; packaging/fixtures/e2e/vertical-slice.mjs
 * validates it and renders the CI job summary from it.
 */
final class VerticalSliceReport
{
    public const string SCHEMA_VERSION = '1.0.0';

    public const string ENV = 'LXAI_SLICE_REPORT';

    public const string DEFAULT_PATH = 'build/e2e/vertical-slice.json';

    /** Blueprint §16 step 8, verbatim order. The driver's STEPS mirror this list. */
    public const array STEPS = [
        'install' => 'Install',
        'create_owner' => 'Create owner',
        'open_pwa' => 'Open PWA',
        'generate_seeded_brief' => 'Generate seeded brief',
        'show_in_menu_bar' => 'Show same brief in menu bar',
        'preview_email' => 'Preview exact email',
    ];

    /** @var list<array<string, mixed>> */
    private array $steps = [];

    /** @var array<string, mixed> */
    private array $brief = [];

    /** @var array<string, array<string, mixed>> */
    private array $surfaces = [];

    /** @var array<string, mixed> */
    private array $assurances = [];

    private ?string $failure = null;

    private readonly CarbonImmutable $startedAt;

    private readonly int $startedAtNs;

    public function __construct(public readonly string $menuBarOracle)
    {
        $this->startedAt = CarbonImmutable::now('UTC');
        $this->startedAtNs = hrtime(true);
    }

    public static function path(): string
    {
        $path = getenv(self::ENV);

        if (is_string($path) && trim($path) !== '') {
            return $path;
        }

        return base_path(self::DEFAULT_PATH);
    }

    /**
     * Runs one step and records it. The step's evidence is whatever the
     * callback returns (an array of named facts); a throwable is recorded as
     * the step's failure and rethrown so Pest reports it.
     *
     * @template T of array<string, mixed>
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function step(string $id, callable $callback): array
    {
        if (! array_key_exists($id, self::STEPS)) {
            throw new \InvalidArgumentException("Unknown slice step `{$id}`");
        }

        $index = array_search($id, array_keys(self::STEPS), true);
        $startedAt = hrtime(true);

        try {
            $evidence = $callback();
        } catch (Throwable $exception) {
            $this->steps[] = [
                'index' => $index + 1,
                'id' => $id,
                'title' => self::STEPS[$id],
                'status' => 'failed',
                'duration_ms' => self::elapsedMs($startedAt),
                'evidence' => [],
                'failure' => $exception::class.': '.$exception->getMessage(),
            ];
            $this->failure = "{$id}: ".$exception->getMessage();

            throw $exception;
        }

        $this->steps[] = [
            'index' => $index + 1,
            'id' => $id,
            'title' => self::STEPS[$id],
            'status' => 'passed',
            'duration_ms' => self::elapsedMs($startedAt),
            'evidence' => $evidence,
        ];

        return $evidence;
    }

    public function brief(string $documentId, int $version, string $generatedAt, string $contentHash, string $source): void
    {
        $this->brief = [
            'document_id' => $documentId,
            'version' => $version,
            'generated_at' => $generatedAt,
            'content_hash' => $contentHash,
            'source' => $source,
        ];
    }

    /** @param array<string, mixed> $extra */
    public function surface(string $name, int $version, string $contentHash, array $extra = []): void
    {
        $this->surfaces[$name] = ['version' => $version, 'content_hash' => $contentHash, ...$extra];
    }

    public function assure(string $name, mixed $value): void
    {
        $this->assurances[$name] = $value;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $passed = $this->failure === null && count($this->steps) === count(self::STEPS)
            && array_all($this->steps, static fn (array $step): bool => $step['status'] === 'passed');

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'slice' => 'blueprint §16 step 8',
            'run_at' => $this->startedAt->toRfc3339String(),
            'duration_ms' => self::elapsedMs($this->startedAtNs),
            'menu_bar_oracle' => $this->menuBarOracle,
            'passed' => $passed,
            'failure' => $this->failure,
            'steps' => $this->steps,
            'brief' => $this->brief === [] ? null : $this->brief,
            'surfaces' => $this->surfaces === [] ? new \stdClass : $this->surfaces,
            'assurances' => $this->assurances === [] ? new \stdClass : $this->assurances,
        ];
    }

    public function write(): string
    {
        $path = self::path();

        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");

        return $path;
    }

    private static function elapsedMs(int $startedAtNs): int
    {
        return (int) round((hrtime(true) - $startedAtNs) / 1_000_000);
    }
}
