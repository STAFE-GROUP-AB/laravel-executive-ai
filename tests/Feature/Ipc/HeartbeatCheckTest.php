<?php

declare(strict_types=1);

use App\Support\Health\HealthService;
use App\Support\Health\Heartbeats;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\CoreReadyState;
use Tests\Support\Ipc\IpcClient;

use function Pest\Laravel\travel;

/*
 * IPC contract §12 `HeartbeatCheckTest` / §6.5 (threat model T-20, T-33):
 * the `menubar_integration` check moves only on an authenticated `menubar`
 * heartbeat; a `supervisor` heartbeat is forbidden_scope and an
 * unauthenticated, unsigned or malformed one never touches the cache.
 */

uses(DatabaseTransactions::class);

function heartbeatBody(array $overrides = []): array
{
    return [...[
        'client' => 'menubar',
        'client_version' => '1.0.0 (120)',
        'contract_version' => '1.0.0',
        'panel_open' => false,
        'since' => '2026-10-10T17:30:00+02:00',
    ], ...$overrides];
}

beforeEach(function (): void {
    CoreReadyState::create();
    app(Heartbeats::class)->forget(Heartbeats::MENUBAR);
    app(HealthService::class)->forgetCached();
});

it('moves menubar_integration from fail to ok on an authenticated menubar heartbeat', function (): void {
    $before = IpcClient::send('GET', '/ipc/v1/health');
    $byName = array_column($before->json('data.checks'), null, 'name');

    expect($before->getStatusCode())->toBe(503)
        ->and($byName['menubar_integration']['status'])->toBe('fail');

    $nonce = IpcClient::nonce();
    $response = IpcClient::send('POST', '/ipc/v1/heartbeat', heartbeatBody(['panel_open' => true]), ['nonce' => $nonce]);

    $response->assertOk();
    expect($response->json('data.next_heartbeat_in_seconds'))->toBe(30)
        ->and($response->json('data.threshold_seconds'))->toBe(120)
        ->and($response->json('data.server.app_version'))->toBe(config('app.version'))
        ->and($response->json('data.received_at'))->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/')
        ->and(IpcClient::responseSignatureIsValid($response, $nonce))->toBeTrue();

    $stored = app(Heartbeats::class)->read(Heartbeats::MENUBAR);

    expect($stored['client_version'])->toBe('1.0.0 (120)')
        ->and($stored['panel_open'])->toBeTrue()
        ->and(app(Heartbeats::class)->ageSeconds(Heartbeats::MENUBAR))->toBe(0)
        ->and(json_encode($stored, JSON_THROW_ON_ERROR))->not->toContain('lxai1.');

    app(HealthService::class)->forgetCached();
    $after = array_column(IpcClient::send('GET', '/ipc/v1/health')->json('data.checks'), null, 'name');

    expect($after['menubar_integration']['status'])->toBe('ok');
});

it('rejects a supervisor heartbeat with forbidden_scope and leaves the check untouched', function (): void {
    $response = IpcClient::send('POST', '/ipc/v1/heartbeat', heartbeatBody(), ['client' => 'supervisor']);

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('forbidden_scope')
        ->and(app(Heartbeats::class)->read(Heartbeats::MENUBAR))->toBeNull();
});

it('never records a heartbeat from an unauthenticated, badly signed or replayed request', function (string $case): void {
    $nonce = IpcClient::nonce();

    $options = match ($case) {
        'no token' => ['token' => null],
        'bad signature' => ['signature' => 'v1='.str_repeat('a', 64)],
        'stale timestamp' => ['timestamp' => time() - 300],
        'wrong host' => ['host' => 'localhost:8787'],
        'browser origin' => ['headers' => ['Sec-Fetch-Mode' => 'cors']],
        default => throw new LogicException("unknown case {$case}"),
    };

    $response = IpcClient::send('POST', '/ipc/v1/heartbeat', heartbeatBody(), ['nonce' => $nonce, ...$options]);

    expect($response->getStatusCode())->toBeIn([401, 403])
        ->and(app(Heartbeats::class)->read(Heartbeats::MENUBAR))->toBeNull();
})->with(['no token', 'bad signature', 'stale timestamp', 'wrong host', 'browser origin']);

it('rejects a heartbeat body that asserts a different identity or client', function (array $overrides, string $field): void {
    $response = IpcClient::send('POST', '/ipc/v1/heartbeat', heartbeatBody($overrides));

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('validation_failed')
        ->and($response->json("error.details.{$field}"))->not->toBeNull()
        ->and(app(Heartbeats::class)->read(Heartbeats::MENUBAR))->toBeNull();
})->with([
    'client supervisor' => [['client' => 'supervisor'], 'client'],
    'user_id injected' => [['user_id' => '01M4G0QK2T6Z5P9Y3R7W1A8C4E'], 'user_id'],
    'owner injected' => [['owner' => 'someone@example.test'], 'owner'],
    'panel_open not boolean' => [['panel_open' => 'yes'], 'panel_open'],
    'since not a timestamp' => [['since' => 'yesterday'], 'since'],
    'contract_version not semver' => [['contract_version' => '1'], 'contract_version'],
]);

it('accepts the final quitting heartbeat and lets the check lapse afterwards', function (): void {
    IpcClient::send('POST', '/ipc/v1/heartbeat', heartbeatBody(['quitting' => true]))->assertOk();

    expect(app(Heartbeats::class)->read(Heartbeats::MENUBAR)['quitting'])->toBeTrue();

    travel(121)->seconds();
    app(HealthService::class)->forgetCached();

    $checks = array_column(IpcClient::send('GET', '/ipc/v1/health')->json('data.checks'), null, 'name');

    expect($checks['menubar_integration']['status'])->toBe('fail');
});

it('rate-limits heartbeats to 6 a minute with Retry-After', function (): void {
    for ($i = 0; $i < 6; $i++) {
        IpcClient::send('POST', '/ipc/v1/heartbeat', heartbeatBody())->assertOk();
    }

    $nonce = IpcClient::nonce();
    $limited = IpcClient::send('POST', '/ipc/v1/heartbeat', heartbeatBody(), ['nonce' => $nonce]);

    $limited->assertStatus(429);
    expect($limited->json('error.code'))->toBe('rate_limited')
        ->and($limited->json('error.retry_after_seconds'))->toBeGreaterThanOrEqual(1)
        ->and((int) $limited->headers->get('Retry-After'))->toBe($limited->json('error.retry_after_seconds'))
        ->and(IpcClient::responseSignatureIsValid($limited, $nonce))->toBeTrue();
});
