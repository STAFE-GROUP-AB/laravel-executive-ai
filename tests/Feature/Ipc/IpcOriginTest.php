<?php

declare(strict_types=1);

use App\Models\AuditEvent;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Testing\TestResponse;
use Tests\Support\CoreReadyState;
use Tests\Support\Ipc\IpcClient;

/*
 * IPC contract §12 `IpcOriginTest` / §5.2 (threat model T-17, T-19): a
 * request with `Host: localhost`, with `X-Forwarded-For`, with `Origin`,
 * with `Sec-Fetch-Mode` — and every other proxy or browser marker — is
 * 403 / origin_rejected, unsigned, before the token is even parsed. A body
 * that is not application/json is 415 after authentication, so no
 * form-encoded simple request can ever reach a controller.
 */

uses(DatabaseTransactions::class);

function expectOriginRejected(TestResponse $response): void
{
    $response->assertStatus(403);

    expect($response->json('ok'))->toBeFalse()
        ->and($response->json('error.code'))->toBe('origin_rejected')
        ->and($response->headers->has('X-Lxai-Signature'))->toBeFalse()
        ->and($response->headers->get('X-Lxai-Origin'))->toBe('127.0.0.1:8787')
        ->and((string) $response->headers->get('Cache-Control'))->toContain('no-store');
}

beforeEach(function (): void {
    CoreReadyState::create();
});

it('rejects a Host other than 127.0.0.1:<port> even though TrustHosts admits it', function (string $host): void {
    expectOriginRejected(IpcClient::send('GET', '/ipc/v1/health', null, ['host' => $host]));
})->with(['localhost:8787', '[::1]:8787', '127.0.0.1:9999', '127.0.0.1']);

it('rejects proxy headers that only Tailscale Serve sets', function (string $header): void {
    expectOriginRejected(IpcClient::send('GET', '/ipc/v1/health', null, ['headers' => [$header => '100.64.0.7']]));
})->with(['X-Forwarded-For', 'X-Forwarded-Host', 'X-Forwarded-Proto', 'Forwarded', 'Tailscale-User-Login', 'Tailscale-User-Name']);

it('rejects browser markers a native client never sends', function (string $header, string $value): void {
    expectOriginRejected(IpcClient::send('GET', '/ipc/v1/health', null, ['headers' => [$header => $value]]));
})->with([
    'Origin' => ['Origin', 'http://127.0.0.1:8787'],
    'Referer' => ['Referer', 'http://127.0.0.1:8787/home'],
    'Cookie' => ['Cookie', 'laravel_session=abc'],
    'Sec-Fetch-Mode' => ['Sec-Fetch-Mode', 'cors'],
    'Sec-Fetch-Site' => ['Sec-Fetch-Site', 'same-origin'],
    'Sec-Fetch-Dest' => ['Sec-Fetch-Dest', 'empty'],
]);

it('rejects a peer that is not 127.0.0.1, including ::1', function (string $peer): void {
    $response = IpcClient::send('GET', '/ipc/v1/health', null, ['remote_addr' => $peer]);

    $response->assertStatus(403);
    expect($response->headers->has('X-Lxai-Signature'))->toBeFalse();
})->with(['::1', '10.0.0.5', '192.168.1.20', '100.64.0.7']);

it('runs the origin check before the token is parsed', function (): void {
    expectOriginRejected(IpcClient::send('GET', '/ipc/v1/health', null, ['host' => 'localhost:8787', 'token' => null, 'version' => '9']));
});

it('applies the same origin rules to /health/ready', function (): void {
    expectOriginRejected(IpcClient::send('GET', '/health/ready', null, ['headers' => ['Sec-Fetch-Mode' => 'navigate']]));
    expectOriginRejected(IpcClient::send('GET', '/health/ready', null, ['host' => 'localhost:8787']));
});

it('answers 415 to a body that is not application/json, after authentication', function (): void {
    $nonce = IpcClient::nonce();

    $response = IpcClient::send('POST', '/ipc/v1/ai/pause', null, [
        'nonce' => $nonce,
        'raw_body' => 'reason=form',
        'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
    ]);

    $response->assertStatus(415);
    expect($response->json('error.code'))->toBe('unsupported_media_type')
        ->and(IpcClient::responseSignatureIsValid($response, $nonce))->toBeTrue();
});

it('audits an origin rejection with reason origin_rejected and no session', function (): void {
    IpcClient::send('GET', '/ipc/v1/health', null, ['headers' => ['Origin' => 'http://evil.example']]);

    $event = AuditEvent::query()->where('action', 'ipc.rejected')->latest('occurred_at')->first();

    expect($event->context['reason'])->toBe('origin_rejected')
        ->and($event->context)->not->toHaveKey('session_id');
});
