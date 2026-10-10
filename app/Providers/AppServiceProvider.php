<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\View\Composers\ShellStateComposer;
use App\Security\Authorization\Otp\OtpHasher;
use App\Security\Authorization\Passkeys\NotYetAvailable;
use App\Security\Authorization\Passkeys\PasskeyAuthenticator;
use App\Security\Queue\RedactingFailedJobProvider;
use App\Security\Secrets\Redactor;
use App\Support\Health\AiReadiness;
use App\Support\Health\ConfiguredProviderAiReadiness;
use App\Support\Health\NoPendingApprovals;
use App\Support\Health\PendingApprovals;
use App\Support\Health\QueueWorkerHeartbeat;
use App\Support\Runtime\RuntimeLayout;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Redactor::class);
        $this->app->singleton(AiReadiness::class, ConfiguredProviderAiReadiness::class);
        $this->app->singleton(PendingApprovals::class, NoPendingApprovals::class);

        $this->registerAuthentication();
        $this->redactFailedJobExceptions();
    }

    /**
     * S1-02: one-time codes are HMACed with the application key; passkeys are
     * the Sprint 1 stub until E2-3 (issue #69) binds a real authenticator.
     */
    private function registerAuthentication(): void
    {
        $this->app->singleton(OtpHasher::class, function (Application $app): OtpHasher {
            $key = (string) $app['config']['app.key'];

            if (str_starts_with($key, 'base64:')) {
                $decoded = base64_decode(substr($key, 7), true);
                $key = $decoded === false ? $key : $decoded;
            }

            return new OtpHasher($key);
        });

        $this->app->singleton(PasskeyAuthenticator::class, function (Application $app): PasskeyAuthenticator {
            return new NotYetAvailable((string) $app['config']['lea.auth.passkeys.tracking_issue']);
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFromTheRuntime();
        $this->ensureSqliteDatabaseFileExists();

        Event::listen(Looping::class, QueueWorkerHeartbeat::class);

        View::composer(['layouts.app', 'home.*', 'briefs.*', 'settings.*'], ShellStateComposer::class);
    }

    /**
     * ADR-001 D4: database_path() points into the data directory under the Mac
     * runtime, but migrations are part of the immutable runtime (base_path). Register
     * that directory explicitly so `migrate` finds them in both layouts; without
     * LEA_DATA_DIR the two paths are the same and the migrator dedupes them.
     */
    private function loadMigrationsFromTheRuntime(): void
    {
        if (RuntimeLayout::dataDirectory() === null) {
            return;
        }

        $this->loadMigrationsFrom(RuntimeLayout::migrationsPath($this->app));
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
