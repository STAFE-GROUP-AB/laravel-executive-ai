<?php

declare(strict_types=1);

/*
 * The static cache-policy audit lives in packaging/fixtures/e2e/sw-cache-audit.test.mjs
 * (node --test). This suite asserts the source is wired into the build and that the
 * registration is the only script the pages load.
 */

it('registers the worker from app.js and nowhere else', function (): void {
    $app = (string) file_get_contents(resource_path('js/app.js'));

    expect($app)->toContain("navigator.serviceWorker.register('/sw.js', { scope: '/' })")
        ->and($app)->toContain('window.isSecureContext');

    $blade = collect(glob(resource_path('views/**/*.blade.php')) ?: [])
        ->merge(glob(resource_path('views/*.blade.php')) ?: [])
        ->map(fn (string $path): string => (string) file_get_contents($path))
        ->implode("\n");

    expect($blade)->not->toContain('<script')
        ->and($blade)->not->toContain('serviceWorker');
});

it('keeps every PWA page path on the service worker deny list', function (): void {
    $source = (string) file_get_contents(resource_path('js/service-worker.js'));

    preg_match('/const NEVER_CACHE = \[(.*?)\];/s', $source, $match);
    preg_match_all('/"(\/[^"]*)"/', $match[1] ?? '', $denied);

    $pagePrefixes = collect(['/home', '/briefs', '/login', '/logout', '/auth', '/ipc', '/health', '/livewire']);

    expect($denied[1])->toContain(...$pagePrefixes->all());

    preg_match('/const SHELL_ALLOWLIST = \[(.*?)\];/s', $source, $match);
    preg_match_all('/"(\/[^"]*)"/', $match[1] ?? '', $allowed);

    foreach ($allowed[1] as $entry) {
        expect($pagePrefixes->contains(fn (string $prefix): bool => str_starts_with($entry, $prefix)))->toBeFalse("{$entry} is cacheable");
    }
});

it('builds public/sw.js from the source when the frontend is built', function (): void {
    $built = (string) file_get_contents(public_path('sw.js'));

    expect($built)->toContain('SHELL_ALLOWLIST')
        ->toContain('NEVER_CACHE')
        ->toContain('"/briefs"')
        ->toMatch('/request\\.method !== ["\']GET["\']/');

    expect(str_contains($built, '__SW_VERSION__'))->toBeFalse();
})->skip(! file_exists(dirname(__DIR__, 3).'/public/build/manifest.json'), 'Frontend not built; run npm run build.');
