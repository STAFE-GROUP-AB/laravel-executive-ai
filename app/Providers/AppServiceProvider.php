<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->ensureSqliteDatabaseFileExists();
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
