<?php

declare(strict_types=1);

namespace App\Providers;

use App\Security\Queue\RedactingFailedJobProvider;
use App\Security\Secrets\Redactor;
use App\Support\Health\AiReadiness;
use App\Support\Health\ConfiguredProviderAiReadiness;
use App\Support\Health\NoPendingApprovals;
use App\Support\Health\PendingApprovals;
use App\Support\Health\QueueWorkerHeartbeat;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Redactor::class);
        $this->app->singleton(AiReadiness::class, ConfiguredProviderAiReadiness::class);
        $this->app->singleton(PendingApprovals::class, NoPendingApprovals::class);

        $this->redactFailedJobExceptions();
    }

    public function boot(): void
    {
        $this->ensureSqliteDatabaseFileExists();

        Event::listen(Looping::class, QueueWorkerHeartbeat::class);
    }

    /**
     * ADR-003 D7 row 3: failed_jobs.exception passes through the Redactor.
     * Only the database-uuids driver is replaced; it is the only driver the
     * `local` and `server` profiles use.
     */
    private function redactFailedJobExceptions(): void
    {
        $this->app->extend('queue.failer', function (FailedJobProviderInterface $failer, Application $app): FailedJobProviderInterface {
            $config = $app['config']['queue.failed'];

            if (($config['driver'] ?? null) !== 'database-uuids') {
                return $failer;
            }

            return new RedactingFailedJobProvider(
                $app['db'],
                $config['database'] ?? null,
                $config['table'] ?? 'failed_jobs',
                $app->make(Redactor::class),
            );
        });
    }

    /**
     * ADR-002 D1: the local profile is a single SQLite file that the installer only
     * has to point at. Laravel refuses to open a missing file, so create it on boot
     * (an empty file is a valid, empty SQLite database). In-memory and URI-style
     * databases are left alone.
     */
    private function ensureSqliteDatabaseFileExists(): void
    {
        $connection = (string) config('database.default');

        if (config("database.connections.{$connection}.driver") !== 'sqlite') {
            return;
        }

        $path = (string) config("database.connections.{$connection}.database");

        if ($path === '' || $path === ':memory:' || str_contains($path, 'mode=memory') || str_starts_with($path, 'file:')) {
            return;
        }

        $absolutePath = str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);

        if (file_exists($absolutePath)) {
            return;
        }

        File::ensureDirectoryExists(dirname($absolutePath));

        touch($absolutePath);
    }
}
