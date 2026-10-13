<?php

declare(strict_types=1);

namespace Tests\Security\Support;

use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionEnum;
use RuntimeException;

/**
 * Inserts one real row into any table that carries `organization_id`, with no
 * per-table knowledge.
 *
 * The IDOR and cross-account suites have to cover **every** §3.1 table, not a
 * list somebody keeps up to date, so they cannot depend on a factory per
 * table — there are thirty-eight tables and three factories. This builds the
 * row from the schema instead: required columns from `Schema::getColumns()`,
 * foreign keys resolved recursively through `Schema::getForeignKeys()`, enum
 * and array columns from the model's own casts so the row hydrates, and JSON
 * columns recognised from the migrations that declared them.
 *
 * The consequence is the property the suites need: a table added in Sprint 3
 * is covered the moment it exists, and a table whose shape the factory cannot
 * satisfy fails loudly here rather than quietly dropping out of the sweep.
 *
 * Only tests use this. Nothing here is a fixture of *meaningful* data — the
 * values are deliberately arbitrary, because the only column these suites
 * assert on is `organization_id`.
 */
final class TenantRowFactory
{
    /** @var array<string, string> table => the id of the row created for this organization */
    private array $created = [];

    /** @var list<string> tables currently being built, so a foreign-key cycle fails loudly */
    private array $building = [];

    /** @var array<string, class-string<Model>>|null */
    private static ?array $models = null;

    /** @var list<string>|null */
    private static ?array $jsonColumns = null;

    /**
     * `$filler` is the user every `user_id` column points at. It must not be
     * any of the principals the test reasons about: a `memberships` row the
     * factory builds for organization B is a real membership, and handing it
     * the probing user would grant exactly the access the suite is trying to
     * prove impossible.
     */
    public function __construct(
        private readonly Organization $organization,
        private readonly User $filler,
    ) {}

    /**
     * Every table in the schema that carries `organization_id` — the tenant
     * boundary, read from the database rather than from a list.
     *
     * @return list<string>
     */
    public static function tenantTables(): array
    {
        $tables = [];

        foreach (Schema::getTableListing(schema: null, schemaQualified: false) as $table) {
            if (in_array('organization_id', array_column(Schema::getColumns($table), 'name'), true)) {
                $tables[] = $table;
            }
        }

        sort($tables);

        return $tables;
    }

    /**
     * The Eloquent model for each tenant table, discovered by instantiating
     * every model class under `app/` and asking it for its table. A table with
     * no model is absent from the map, which is what
     * `PolicySurfaceArchTest` keys its "no model, no policy needed — yet"
     * branch on.
     *
     * @return array<string, class-string<Model>>
     */
    public static function modelsByTable(): array
    {
        if (self::$models !== null) {
            return self::$models;
        }

        $models = [];

        foreach (self::modelClasses() as $class) {
            $model = new $class;
            $models[$model->getTable()] = $class;
        }

        return self::$models = $models;
    }

    /**
     * Every concrete Eloquent model in the application.
     *
     * @return list<class-string<Model>>
     */
    public static function modelClasses(): array
    {
        $classes = [];

        foreach (self::phpFilesUnder(app_path()) as $file) {
            $class = self::classNameFor($file);

            if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                continue;
            }

            $reflection = new \ReflectionClass($class);

            if ($reflection->isAbstract()) {
                continue;
            }

            $classes[] = $class;
        }

        sort($classes);

        return $classes;
    }

    /** Insert a row for this organization, memoized so a table gets one row per organization. */
    public function insert(string $table): string
    {
        if (isset($this->created[$table])) {
            return $this->created[$table];
        }

        if (in_array($table, $this->building, true)) {
            throw new RuntimeException('Foreign-key cycle through '.implode(' → ', [...$this->building, $table]));
        }

        $this->building[] = $table;

        try {
            $id = (string) Str::ulid();
            DB::table($table)->insert($this->attributes($table, $id));

            return $this->created[$table] = $id;
        } finally {
            array_pop($this->building);
        }
    }

    /**
     * The row this factory created in `$table`, hydrated through its model so
     * the policy sees the same object a controller would.
     */
    public function model(string $table): Model
    {
        $class = self::modelsByTable()[$table] ?? null;

        if ($class === null) {
            throw new RuntimeException("No Eloquent model for {$table}; use TenantRowGuard for a table without one.");
        }

        $id = $this->insert($table);
        $model = $class::query()->withoutGlobalScopes()->find($id);

        if (! $model instanceof Model) {
            throw new RuntimeException("The row inserted into {$table} did not come back through {$class}.");
        }

        return $model;
    }

    /** @return array<string, mixed> */
    private function attributes(string $table, string $id): array
    {
        $foreignKeys = $this->foreignKeyTargets($table);
        $casts = $this->castsFor($table);
        $attributes = [];

        foreach (Schema::getColumns($table) as $column) {
            $name = (string) $column['name'];

            if ($name === 'id') {
                $attributes['id'] = $id;

                continue;
            }

            if ($name === 'organization_id') {
                $attributes['organization_id'] = (string) $this->organization->id;

                continue;
            }

            if ($column['generation'] !== null || $column['auto_increment'] === true) {
                continue;
            }

            if ($column['nullable'] === true || $column['default'] !== null) {
                continue;
            }

            $attributes[$name] = isset($foreignKeys[$name])
                ? $this->foreignValue($foreignKeys[$name])
                : $this->value($name, $column, $casts[$name] ?? null);
        }

        return $attributes;
    }

    /** @return array<string, string> column => referenced table */
    private function foreignKeyTargets(string $table): array
    {
        $targets = [];

        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            foreach ((array) $foreignKey['columns'] as $index => $column) {
                $targets[(string) $column] = (string) $foreignKey['foreign_table'];
            }
        }

        return $targets;
    }

    private function foreignValue(string $referencedTable): string
    {
        return match ($referencedTable) {
            'organizations' => (string) $this->organization->id,
            'users' => (string) $this->filler->id,
            default => $this->insert($referencedTable),
        };
    }

    /**
     * Enum and array casts come from the model, so the row hydrates: a policy
     * reads the object, and an invalid enum value would throw before the
     * policy was ever asked.
     *
     * @return array<string, string>
     */
    private function castsFor(string $table): array
    {
        $class = self::modelsByTable()[$table] ?? null;

        if ($class === null) {
            return [];
        }

        $casts = [];

        foreach ((new $class)->getCasts() as $attribute => $cast) {
            $casts[(string) $attribute] = (string) $cast;
        }

        return $casts;
    }

    /** @param array<string, mixed> $column */
    private function value(string $name, array $column, ?string $cast): mixed
    {
        if ($cast !== null && enum_exists($cast)) {
            return $this->firstEnumValue($cast);
        }

        if ($this->isJson($name, $cast)) {
            return '[]';
        }

        $type = strtolower((string) $column['type_name']);

        return match (true) {
            str_contains($type, 'bool') => 0,
            in_array($type, ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint'], true) => 1,
            in_array($type, ['decimal', 'numeric', 'float', 'double', 'real'], true) => '1.00',
            in_array($type, ['date', 'datetime', 'timestamp'], true) => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s'),
            default => $this->string($column),
        };
    }

    private function isJson(string $name, ?string $cast): bool
    {
        if (in_array($cast, ['array', 'json', 'object', 'collection'], true)) {
            return true;
        }

        return in_array($name, self::jsonColumns(), true);
    }

    /**
     * Columns declared with `->json()` anywhere in the migrations, plus
     * `provenance` from `BusinessObjectColumns::lineage()`. Read from the
     * migrations rather than listed here, so a JSON column added later is
     * recognised without editing this file. On SQLite a JSON column reports as
     * `text`, so the type cannot answer this question.
     *
     * @return list<string>
     */
    private static function jsonColumns(): array
    {
        if (self::$jsonColumns !== null) {
            return self::$jsonColumns;
        }

        $columns = ['provenance'];

        foreach (glob(database_path('migrations/*.php')) ?: [] as $file) {
            preg_match_all('/->json\(\s*\'([a-z0-9_]+)\'/i', (string) file_get_contents($file), $matches);

            foreach ($matches[1] as $column) {
                $columns[] = (string) $column;
            }
        }

        return self::$jsonColumns = array_values(array_unique($columns));
    }

    /** @param class-string $enum */
    private function firstEnumValue(string $enum): string|int
    {
        $cases = (new ReflectionEnum($enum))->getCases();

        if ($cases === []) {
            throw new RuntimeException("{$enum} has no cases.");
        }

        /** @var object{value?: string|int} $case */
        $case = $cases[0]->getValue();

        return $case->value ?? '';
    }

    /**
     * A ULID truncated to the column's length. MySQL reports the length and
     * enforces it; SQLite reports neither, so the full ULID is used there and
     * the same row shape works on both profiles (ADR-002 D1).
     *
     * @param  array<string, mixed>  $column
     */
    private function string(array $column): string
    {
        $value = (string) Str::ulid();

        if (preg_match('/\((\d+)\)/', (string) $column['type'], $matches) === 1) {
            $length = (int) $matches[1];

            return $length < 1 ? '' : substr($value, 0, $length);
        }

        return $value;
    }

    /** @return list<string> */
    private static function phpFilesUnder(string $directory): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /** @return class-string */
    private static function classNameFor(string $file): string
    {
        $relative = substr($file, strlen(app_path()) + 1, -4);

        /** @var class-string $class */
        $class = 'App\\'.str_replace(DIRECTORY_SEPARATOR, '\\', $relative);

        return $class;
    }
}
