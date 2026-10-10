<?php

declare(strict_types=1);

use Illuminate\Support\Env;

/*
 * ADR-003 D7 row 2 / threat model T-11: the packaged bundle runs with debug
 * off. config/app.php must default APP_DEBUG to false and APP_ENV to
 * production so a missing or stripped .env can never turn the debug page on.
 */

it('defaults APP_DEBUG to false when the environment says nothing', function (): void {
    $repository = Env::getRepository();
    $previousDebug = $repository->get('APP_DEBUG');

    $repository->clear('APP_DEBUG');

    try {
        $config = require base_path('config/app.php');
    } finally {
        if ($previousDebug !== null) {
            $repository->set('APP_DEBUG', $previousDebug);
        }
    }

    expect($config['debug'])->toBeFalse();
});

it('defaults APP_ENV to production and never reads APP_DEBUG through a truthy fallback', function (): void {
    $source = (string) file_get_contents(base_path('config/app.php'));

    expect($source)->toContain("'debug' => (bool) env('APP_DEBUG', false)")
        ->toContain("'env' => env('APP_ENV', 'production')");
});

it('ships no environment template that enables debug in production', function (): void {
    foreach (glob(base_path('.env*')) ?: [] as $file) {
        if (basename($file) === '.env' || str_ends_with($file, '.example') && str_contains((string) file_get_contents($file), 'APP_ENV=local')) {
            continue;
        }

        $contents = (string) file_get_contents($file);

        if (str_contains($contents, 'APP_ENV=production')) {
            expect($contents)->not->toContain('APP_DEBUG=true', basename($file).' turns debug on in production');
        }
    }

    expect(true)->toBeTrue();
});
