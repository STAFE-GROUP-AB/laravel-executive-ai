<?php

declare(strict_types=1);

use App\Models\AuditEvent;
use App\Security\Ipc\IpcNonceCache;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\Support\CoreReadyState;
use Tests\Support\Ipc\IpcClient;

use function Pest\Laravel\travel;

/*
 * IPC contract §12 `IpcReplayTest` (threat model T-30, T-31): the same nonce
 * twice is nonce_replayed; timestamps −31 s / +31 s are timestamp_skew and
 * −29 s / +29 s are accepted; the nonce cache is per session and lives
 * 120 s; the (timestamp, nonce) pair is in the rejection audit.
 */

uses(DatabaseTransactions::class);

beforeEach(function (): void {
    CoreReadyState::create();
});

it('rejects the same nonce a second time within the window, with a signed response', function (): void {
    $nonce = IpcClient::nonce();

    IpcClient::send('GET', '/ipc/v1/health', null, ['nonce' => $nonce])->assertOk();
    $replayed = IpcClient::send('GET', '/ipc/v1/health', null, ['nonce' => $nonce]);

    $replayed->assertStatus(401);
    expect($replayed->json('error.code'))->toBe('nonce_replayed')
        ->and(IpcClient::responseSignatureIsValid($replayed, $nonce))->toBeTrue()
        ->and(Cache::has(IpcNonceCache::key(IpcClient::SESSION, $nonce)))->toBeTrue();
});

it('rejects a replayed mutating request before it reaches the controller', function (): void {
    $nonce = IpcClient::nonce();
    $body = ['reason' => 'replay-test'];

    IpcClient::send('POST', '/ipc/v1/ai/pause', $body, ['nonce' => $nonce])->assertOk();
    IpcClient::send('POST', '/ipc/v1/ai/resume', [], [])->assertOk();

    $replayed = IpcClient::send('POST', '/ipc/v1/ai/pause', $body, ['nonce' => $nonce]);

    $replayed->assertStatus(401);
    expect($replayed->json('error.code'))->toBe('nonce_replayed')
        ->and(IpcClient::send('GET', '/ipc/v1/health')->json('data.ready'))->toBeTrue()
        ->and(AuditEvent::query()->where('action', 'ai.paused')->count())->toBe(1);
});

it('rejects timestamps outside ±30 s and accepts those inside', function (int $offset, bool $accepted): void {
    $response = IpcClient::send('GET', '/ipc/v1/health', null, ['timestamp' => CarbonImmutable::now('UTC')->getTimestamp() + $offset]);

    if ($accepted) {
        $response->assertOk();

        return;
    }

    $response->assertStatus(401);
    expect($response->json('error.code'))->toBe('timestamp_skew')
        ->and($response->json('server_time'))->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/');
})->with([
    '-31 s' => [-31, false],
    '+31 s' => [31, false],
    '-29 s' => [-29, true],
    '+29 s' => [29, true],
    '-30 s (boundary)' => [-30, true],
]);

it('treats a malformed timestamp as skew', function (string $timestamp): void {
    $response = IpcClient::send('GET', '/ipc/v1/health', null, ['timestamp' => $timestamp]);

    $response->assertStatus(401);
    expect($response->json('error.code'))->toBe('timestamp_skew');
})->with(['abc', '', '1791648005000.5', '-1', '2026-10-10T18:00:05+02:00']);

it('treats a malformed nonce as a replay', function (string $nonce): void {
    $response = IpcClient::send('GET', '/ipc/v1/health', null, ['nonce' => $nonce]);

    $response->assertStatus(401);
    expect($response->json('error.code'))->toBe('nonce_replayed');
})->with(['short', 'has+plus/slashes+slashes', 'YWktcGF1c2UuLi4uLi4uLg==', 'way-too-long-for-sixteen-bytes-of-nonce']);

it('scopes nonces per session so re-pairing cannot resurrect one', function (): void {
    $nonce = IpcClient::nonce();

    IpcClient::send('GET', '/ipc/v1/health', null, ['nonce' => $nonce])->assertOk();
    IpcClient::send('GET', '/ipc/v1/health', null, ['nonce' => $nonce, 'session_id' => '01M4G0R2Y3K8F6V1N7T5B9D4HA'])->assertOk();
});

it('forgets a nonce after the 120 s window', function (): void {
    $nonce = IpcClient::nonce();

    IpcClient::send('GET', '/ipc/v1/health', null, ['nonce' => $nonce])->assertOk();

    travel(121)->seconds();

    // Heartbeats have aged past their thresholds by now, so ready is 503; the nonce is what matters here.
    expect(IpcClient::send('GET', '/ipc/v1/health', null, ['nonce' => $nonce])->json('ok'))->toBeTrue();
});

it('does not consume the nonce of a request whose signature failed', function (): void {
    $nonce = IpcClient::nonce();

    $bad = IpcClient::send('GET', '/ipc/v1/health', null, ['nonce' => $nonce, 'signature' => 'v1='.str_repeat('0', 64)]);

    expect($bad->json('error.code'))->toBe('signature_invalid')
        ->and(Cache::has(IpcNonceCache::key(IpcClient::SESSION, $nonce)))->toBeFalse();

    IpcClient::send('GET', '/ipc/v1/health', null, ['nonce' => $nonce])->assertOk();
});

it('logs the (timestamp, nonce) pair on a skew rejection so drift and replay are distinguishable', function (): void {
    $nonce = IpcClient::nonce();
    $timestamp = CarbonImmutable::now('UTC')->getTimestamp() - 90;

    IpcClient::send('GET', '/ipc/v1/health', null, ['nonce' => $nonce, 'timestamp' => $timestamp])->assertStatus(401);

    $event = AuditEvent::query()->where('action', 'ipc.rejected')->latest('occurred_at')->first();

    expect($event->context['reason'])->toBe('timestamp_skew')
        ->and($event->context['ts'])->toBe((string) $timestamp)
        ->and($event->context['nonce'])->toBe($nonce)
        ->and($event->context['client'])->toBe('menubar')
        ->and($event->context['session_id'])->toBe(IpcClient::SESSION);
});
