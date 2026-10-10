<?php

declare(strict_types=1);

namespace App\Support\Runtime;

use Illuminate\Contracts\Foundation\Application as ApplicationContract;
use Illuminate\Foundation\Application;

/**
 * ADR-001 D4: the Laravel tree lives inside an immutable, versioned runtime
 * directory that is never written at run time, while everything mutable lives
 * in the separate user data directory. The Supervisor (S1-07) passes that
 * directory as LEA_DATA_DIR; this class is the one place that turns it into
 * Laravel paths, so storage_path() and database_path() resolve into data/ and
 * base_path() stays in the runtime (docs/architecture/runtime-layout.md §3).
 *
 * Without LEA_DATA_DIR (development, tests, the server profile) nothing changes.
 */
final class RuntimeLayout
{
    public const DATA_DIR_ENV = 'LEA_DATA_DIR';

    public const RUNTIME_DIR_ENV = 'LEA_RUNTIME_DIR';

    public const DATABASE_FILE = 'executive.sqlite';

    /**
     * Points the application's storage and database paths into the data directory.
     * Called from bootstrap/app.php before any provider or config file is loaded.
     */
    public static function apply(Application $app, ?string $dataDirectory = null): void
    {
        $dataDirectory ??= self::dataDirectory();

        if ($dataDirectory === null) {
            return;
        }

        $app->useStoragePath(self::storagePath($dataDirectory));
        $app->useDatabasePath(self::databasePath($dataDirectory));
    }

    /**
     * The absolute data directory from the process environment, or null when the
     * variable is absent, empty or not absolute. A relative value is refused rather
     * than resolved: nothing may silently land inside the runtime directory.
     */
    public static function dataDirectory(): ?string
    {
        $value = self::environmentValue(self::DATA_DIR_ENV);

        if ($value === null || ! str_starts_with($value, DIRECTORY_SEPARATOR)) {
            return null;
        }

        return rtrim($value, DIRECTORY_SEPARATOR);
    }

    /**
     * The runtime directory the Supervisor says it launched us from, if any.
     * Informational: base_path() is derived from the file system, not from this.
     */
    public static function runtimeDirectory(): ?string
    {
        $value = self::environmentValue(self::RUNTIME_DIR_ENV);

        if ($value === null || ! str_starts_with($value, DIRECTORY_SEPARATOR)) {
            return null;
        }

        return rtrim($value, DIRECTORY_SEPARATOR);
    }

    public static function storagePath(string $dataDirectory): string
    {
        return $dataDirectory.DIRECTORY_SEPARATOR.'storage';
    }

    public static function databasePath(string $dataDirectory): string
    {
        return $dataDirectory.DIRECTORY_SEPARATOR.'database';
    }

    /**
     * The SQLite file config/database.php defaults DB_DATABASE to:
     * <LEA_DATA_DIR>/database/executive.sqlite when the data directory is set,
     * the repository's database/database.sqlite otherwise.
     */
    public static function defaultDatabaseFile(): string
    {
        $dataDirectory = self::dataDirectory();

        if ($dataDirectory === null) {
            return database_path('database.sqlite');
        }

        return self::databasePath($dataDirectory).DIRECTORY_SEPARATOR.self::DATABASE_FILE;
    }

    /**
     * Migrations ship inside the runtime (base_path), not next to the data; the
     * migrator must be told so once database_path() points into data/.
     */
    public static function migrationsPath(ApplicationContract $app): string
    {
        return $app->basePath('database'.DIRECTORY_SEPARATOR.'migrations');
    }

    private static function environmentValue(string $name): ?string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
