<?php

declare(strict_types=1);

use App\Support\Runtime\RuntimeLayout;
use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\File;

/*
 * ADR-001 D4 / S1-06: with LEA_DATA_DIR set by the Supervisor, storage_path() and
 * database_path() resolve into the mutable data directory while base_path()
 * stays inside the immutable runtime; the SQLite file defaults to
 * <LEA_DATA_DIR>/database/executive.sqlite. Without the variable nothing changes.
 */

/** Temporary data directories created by a test; removed after it. */
final class RuntimeLayoutFixture
{
    /** @var list<string> */
    private static array $directories = [];

    public static function dataDirectory(): string
    {
        $directory = sys_get_temp_dir().'/lxai-layout-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($directory);
        self::$directories[] = $directory;

        return $directory;
    }

    /** The directories the Mac installer creates (InstallPaths.dataSubdirectories). */
    public static function installerShaped(): string
    {
        $directory = self::dataDirectory();

        foreach (['database', 'storage/app/private', 'storage/app/public', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'backups'] as $subdirectory) {
            File::ensureDirectoryExists("{$directory}/{$subdirectory}");
        }

        return $directory;
    }

    public static function withDataDir(?string $value): void
    {
        if ($value === null) {
            unset($_SERVER[RuntimeLayout::DATA_DIR_ENV], $_ENV[RuntimeLayout::DATA_DIR_ENV]);
            putenv(RuntimeLayout::DATA_DIR_ENV);

            return;
        }

        $_SERVER[RuntimeLayout::DATA_DIR_ENV] = $value;
    }

    /**
     * Boots a second application through the real bootstrap/app.php, then hands the
     * container singleton back to the test application so the rest of the suite is unaffected.
     *
     * @template T
     *
     * @param  callable(Application): T  $callback
     * @return T
     */
    public static function withBootstrappedApplication(callable $callback): mixed
    {
        $testApp = app();

        try {
            /** @var Application $app */
            $app = require base_path('bootstrap/app.php');

            return $callback($app);
        } finally {
            Container::setInstance($testApp);
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($testApp);
        }
    }

    public static function cleanUp(): void
    {
        self::withDataDir(null);

        foreach (self::$directories as $directory) {
            File::deleteDirectory($directory);
        }

        self::$directories = [];
    }
}

afterEach(fn () => RuntimeLayoutFixture::cleanUp());

it('reads an absolute LEA_DATA_DIR and refuses relative or empty values', function (): void {
    $dataDir = RuntimeLayoutFixture::dataDirectory();

    RuntimeLayoutFixture::withDataDir($dataDir.'/');
    expect(RuntimeLayout::dataDirectory())->toBe($dataDir);

    RuntimeLayoutFixture::withDataDir('relative/data');
    expect(RuntimeLayout::dataDirectory())->toBeNull();

    RuntimeLayoutFixture::withDataDir('   ');
    expect(RuntimeLayout::dataDirectory())->toBeNull();

    RuntimeLayoutFixture::withDataDir(null);
    expect(RuntimeLayout::dataDirectory())->toBeNull();
});

it('points storage_path and database_path into the data directory and keeps base_path in the runtime', function (): void {
    $dataDir = RuntimeLayoutFixture::dataDirectory();
    RuntimeLayoutFixture::withDataDir($dataDir);

    RuntimeLayoutFixture::withBootstrappedApplication(function (Application $app) use ($dataDir): void {
        expect($app->basePath())->toBe(base_path())
            ->and($app->storagePath())->toBe("{$dataDir}/storage")
            ->and($app->storagePath('logs/laravel.log'))->toBe("{$dataDir}/storage/logs/laravel.log")
            ->and($app->databasePath())->toBe("{$dataDir}/database")
            ->and($app->databasePath('executive.sqlite'))->toBe("{$dataDir}/database/executive.sqlite")
            ->and(str_starts_with($app->storagePath(), $app->basePath()))->toBeFalse('nothing mutable resolves inside the runtime');
    });
});

it('leaves every path alone when LEA_DATA_DIR is not set', function (): void {
    RuntimeLayoutFixture::withDataDir(null);

    RuntimeLayoutFixture::withBootstrappedApplication(function (Application $app): void {
        expect($app->storagePath())->toBe(base_path('storage'))
            ->and($app->databasePath())->toBe(base_path('database'));
    });
});

it('defaults the SQLite file to <LEA_DATA_DIR>/database/executive.sqlite', function (): void {
    $dataDir = RuntimeLayoutFixture::dataDirectory();
    RuntimeLayoutFixture::withDataDir($dataDir);
    expect(RuntimeLayout::defaultDatabaseFile())->toBe("{$dataDir}/database/executive.sqlite");

    // phpunit.xml pins DB_DATABASE for the suite (in $_ENV and putenv, not $_SERVER); lift it
    // for one require so the default shows, then put back exactly what was there.
    $saved = ['server' => $_SERVER['DB_DATABASE'] ?? null, 'env' => $_ENV['DB_DATABASE'] ?? null, 'putenv' => getenv('DB_DATABASE')];
    unset($_SERVER['DB_DATABASE'], $_ENV['DB_DATABASE']);
    putenv('DB_DATABASE');

    try {
        $config = require base_path('config/database.php');
    } finally {
        if (is_string($saved['server'])) {
            $_SERVER['DB_DATABASE'] = $saved['server'];
        }

        if (is_string($saved['env'])) {
            $_ENV['DB_DATABASE'] = $saved['env'];
        }

        if (is_string($saved['putenv'])) {
            putenv("DB_DATABASE={$saved['putenv']}");
        }
    }

    expect($config['connections']['sqlite']['database'])->toBe("{$dataDir}/database/executive.sqlite")
        ->and($config['connections']['sqlite']['journal_mode'])->toBe('WAL');

    RuntimeLayoutFixture::withDataDir(null);
    expect(RuntimeLayout::defaultDatabaseFile())->toBe(database_path('database.sqlite'));
});

it('boots against an installer-shaped data directory and registers the runtime migrations', function (): void {
    $dataDir = RuntimeLayoutFixture::installerShaped();
    RuntimeLayoutFixture::withDataDir($dataDir);

    $paths = RuntimeLayoutFixture::withBootstrappedApplication(function (Application $app): array {
        $app->make(Kernel::class)->bootstrap();

        return $app->make('migrator')->paths();
    });

    expect($paths)->toContain(base_path('database/migrations'))
        ->and(File::isDirectory(base_path('storage')))->toBeTrue('the runtime tree is untouched')
        ->and(File::exists("{$dataDir}/storage/framework/views/.gitignore"))->toBeFalse('nothing is written into data/ just by booting');
});

it('bootstrap/app.php applies the layout before returning the application', function (): void {
    $source = (string) file_get_contents(base_path('bootstrap/app.php'));

    expect($source)->toContain('RuntimeLayout::apply($app);')
        ->and($source)->toContain('return $app;')
        ->and($source)->not->toContain('return Application::configure');
});
