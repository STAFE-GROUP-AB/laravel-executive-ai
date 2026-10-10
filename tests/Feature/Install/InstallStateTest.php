<?php

declare(strict_types=1);

use App\Support\Install\InstallState;
use App\Support\Install\InstallStateStore;
use Illuminate\Foundation\Testing\DatabaseTransactions;

use function Pest\Laravel\artisan;

uses(DatabaseTransactions::class);

it('walks the installer marker from not_installed to ai_ready', function (): void {
    $store = app(InstallStateStore::class);

    $store->transition(InstallState::NotInstalled, 'test baseline');
    expect($store->current())->toBe(InstallState::NotInstalled)
        ->and($store->current()->isReady())->toBeFalse();

    $store->transition(InstallState::Installing, 'INSTALL_CORE');
    $store->transition(InstallState::CoreReady, 'HEALTH_CHECK passed');
    expect($store->current())->toBe(InstallState::CoreReady)
        ->and($store->current()->isReady())->toBeTrue();

    $store->transition(InstallState::AiReady, 'first completion succeeded');
    expect($store->current())->toBe(InstallState::AiReady)
        ->and($store->latest()?->app_version)->toBe(config('app.version'));
});

it('distinguishes all four states', function (): void {
    expect(array_column(InstallState::cases(), 'value'))->toBe(['not_installed', 'installing', 'core_ready', 'ai_ready']);
});

it('reads and sets the marker through lea:install-state', function (): void {
    artisan('lea:install-state', ['state' => 'installing', '--reason' => 'test'])->assertSuccessful();
    artisan('lea:install-state')->expectsOutputToContain('Install state: installing')->assertSuccessful();
    artisan('lea:install-state', ['state' => 'core_ready', '--json' => true])->assertSuccessful();

    expect(app(InstallStateStore::class)->current())->toBe(InstallState::CoreReady);
});

it('rejects an unknown state', function (): void {
    artisan('lea:install-state', ['state' => 'half_done'])->assertFailed();
});
