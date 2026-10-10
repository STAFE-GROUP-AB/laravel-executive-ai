<?php

declare(strict_types=1);

use App\Models\AuditEvent;
use App\Security\Ipc\IpcKey;
use App\Security\Ipc\IpcKeySource;
use App\Security\Ipc\UnavailableKeySource;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Testing\TestResponse;
use Tests\Support\CoreReadyState;
use Tests\Support\Ipc\IpcClient;

/*
 * IPC contract §12 `IpcTokenTest` / S1-08 criterion 2 (threat model T-20,
 * T-31, T-33, T-34): a missing, malformed, wrong-MAC, expired or
 * wrong-client token is rejected with the recorded 401 / 403, one test per
 * case; the rejection never echoes the token; /health/ready is 401 without
 * one; a token minted under a rotated key is dead at once.
 */

uses(DatabaseTransactions::class);

function expectUnsignedSessionInvalid(TestResponse $response, ?string $token = null): void
{
    $response->assertStatus(401);

    expect($response->json('ok'))->toBeFalse()
        ->and($response->json('error.code'))->toBe('session_invalid')
        ->and($response->json('error.hint'))->toContain('Reset security pairing')
        ->and($response->headers->has('X-Lxai-Signature'))->toBeFalse()
        ->and($response->headers->get('X-Lxai-Ipc-Version'))->toBe('1')
        ->and($response->headers->get('X-Lxai-Request-Id'))->toMatch('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/');

    if ($token !== null) {
        expect((string) $response->getContent())->not->toContain($token);
    }
}

beforeEach(function (): void {
    CoreReadyState::create();
});

it('accepts a valid menubar token and signs the response', function (): void {
    $nonce = IpcClient::nonce();

    $response = IpcClient::send('GET', '/ipc/v1/health', null, ['nonce' => $nonce]);

    $response->assertOk();
    expect($response->json('ok'))->toBeTrue()
        ->and(IpcClient::responseSignatureIsValid($response, $nonce))->toBeTrue();
});

it('rejects a request with no Authorization header', function (): void {
    expectUnsignedSessionInvalid(IpcClient::send('GET', '/ipc/v1/health', null, ['token' => null]));
});

it('rejects a non-Bearer Authorization scheme', function (): void {
    $response = IpcClient::send('GET', '/ipc/v1/health', null, ['headers' => ['Authorization' => 'Token '.IpcClient::token()]]);

    expectUnsignedSessionInvalid($response);
});

it('rejects a malformed token', function (string $token): void {
    expectUnsignedSessionInvalid(IpcClient::send('GET', '/ipc/v1/health', null, ['token' => $token]), $token);
})->with([
    'garbage' => 'not-a-token',
    'wrong prefix' => 'lxai2.menubar.01M4G0QK2T6Z5P9Y3R7W1A8C4E.1794240000.f8c50b991bd35d59ed0fa74ffdfe4bbf',
    'unknown client' => 'lxai1.browser.01M4G0QK2T6Z5P9Y3R7W1A8C4E.1794240000.f8c50b991bd35d59ed0fa74ffdfe4bbf',
    'bad session id' => 'lxai1.menubar.not-a-ulid.1794240000.f8c50b991bd35d59ed0fa74ffdfe4bbf',
    'short mac' => 'lxai1.menubar.01M4G0QK2T6Z5P9Y3R7W1A8C4E.1794240000.f8c50b99',
    'uppercase mac' => 'lxai1.menubar.01M4G0QK2T6Z5P9Y3R7W1A8C4E.1794240000.F8C50B991BD35D59ED0FA74FFDFE4BBF',
]);

it('rejects a token whose MAC does not verify under K', function (): void {
    $token = IpcClient::token();
    $tampered = substr($token, 0, -32).strrev(substr($token, -32));

    expectUnsignedSessionInvalid(IpcClient::send('GET', '/ipc/v1/health', null, ['token' => $tampered]), $tampered);
});

it('rejects a token whose client was changed after minting', function (): void {
    $token = str_replace('lxai1.menubar.', 'lxai1.supervisor.', IpcClient::token('menubar'));

    expectUnsignedSessionInvalid(IpcClient::send('GET', '/ipc/v1/health', null, ['token' => $token]), $token);
});

it('rejects an expired token', function (): void {
    $expired = IpcClient::token(expiresAt: CarbonImmutable::now('UTC')->subSecond()->getTimestamp());

    expectUnsignedSessionInvalid(IpcClient::send('GET', '/ipc/v1/health', null, ['token' => $expired]), $expired);
});

it('rejects a token that expires exactly now', function (): void {
    $token = IpcClient::token(expiresAt: CarbonImmutable::now('UTC')->getTimestamp());

    expectUnsignedSessionInvalid(IpcClient::send('GET', '/ipc/v1/health', null, ['token' => $token]));
});

it('rejects the supervisor token on a menu-bar route with a signed forbidden_scope', function (string $method, string $path, ?array $body): void {
    $nonce = IpcClient::nonce();

    $response = IpcClient::send($method, $path, $body, ['client' => 'supervisor', 'nonce' => $nonce]);

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('forbidden_scope')
        ->and($response->json('error.message'))->toBe('This client is not allowed to call this operation.')
        ->and(IpcClient::responseSignatureIsValid($response, $nonce, 'supervisor'))->toBeTrue();
})->with([
    'approvals' => ['GET', '/ipc/v1/approvals?state=pending', null],
    'briefs' => ['GET', '/ipc/v1/briefs/latest', null],
    'pause' => ['POST', '/ipc/v1/ai/pause', []],
    'heartbeat' => ['POST', '/ipc/v1/heartbeat', ['client' => 'menubar', 'client_version' => '1', 'contract_version' => '1.0.0', 'panel_open' => false, 'since' => '2026-10-10T17:30:00+02:00']],
    'remote access' => ['GET', '/ipc/v1/remote-access', null],
]);

it('accepts the supervisor token on its two probe routes', function (string $path): void {
    $nonce = IpcClient::nonce();

    $response = IpcClient::send('GET', $path, null, ['client' => 'supervisor', 'nonce' => $nonce]);

    $response->assertOk();
    expect(IpcClient::responseSignatureIsValid($response, $nonce, 'supervisor'))->toBeTrue();
})->with(['/ipc/v1/health', '/health/ready']);

it('answers 401 to an unauthenticated /health/ready while /health/live stays open', function (): void {
    expectUnsignedSessionInvalid(IpcClient::send('GET', '/health/ready', null, ['token' => null]));

    IpcClient::send('GET', '/health/live', null, ['token' => null, 'version' => null])->assertOk();
});

it('invalidates every token at once when K is rotated', function (): void {
    $token = IpcClient::token();

    IpcClient::send('GET', '/ipc/v1/health', null, ['token' => $token])->assertOk();

    app()->instance(IpcKeySource::class, new class implements IpcKeySource
    {
        public function key(): IpcKey
        {
            return IpcKey::fromBytes(str_repeat("\x42", 32));
        }
    });

    expectUnsignedSessionInvalid(IpcClient::send('GET', '/ipc/v1/health', null, ['token' => $token]), $token);
});

it('fails closed with an unsigned 503 secrets_unavailable when K cannot be read', function (string $path): void {
    app()->instance(IpcKeySource::class, new UnavailableKeySource);

    $response = IpcClient::send('GET', $path);

    $response->assertStatus(503);
    expect($response->json('error.code'))->toBe('secrets_unavailable')
        ->and($response->json('error.retry_after_seconds'))->toBeInt()
        ->and($response->headers->get('Retry-After'))->not->toBeNull()
        ->and($response->headers->has('X-Lxai-Signature'))->toBeFalse();
})->with(['/ipc/v1/health', '/health/ready', '/ipc/v1/briefs/latest']);

it('writes ipc.rejected with the reason code and never the token', function (): void {
    $token = IpcClient::token(expiresAt: CarbonImmutable::now('UTC')->subMinute()->getTimestamp());
    $nonce = IpcClient::nonce();

    IpcClient::send('GET', '/ipc/v1/health', null, ['token' => $token, 'nonce' => $nonce])->assertStatus(401);

    $event = AuditEvent::query()->where('action', 'ipc.rejected')->latest('occurred_at')->first();

    expect($event)->not->toBeNull()
        ->and($event->outcome)->toBe('refused')
        ->and($event->context['reason'])->toBe('session_invalid')
        ->and($event->context['nonce'])->toBe($nonce)
        ->and($event->context)->toHaveKey('ts')
        ->and($event->context)->not->toHaveKey('client')
        ->and(json_encode($event->getAttributes(), JSON_THROW_ON_ERROR))->not->toContain($token);
});

it('uses the presented request id when it is a ULID and mints one otherwise', function (): void {
    $echoed = IpcClient::send('GET', '/ipc/v1/health', null, ['request_id' => '01M4G06BJDY51YZX0PWRBQ8EBJ']);
    $minted = IpcClient::send('GET', '/ipc/v1/health', null, ['request_id' => 'not-a-ulid']);

    expect($echoed->headers->get('X-Lxai-Request-Id'))->toBe('01M4G06BJDY51YZX0PWRBQ8EBJ')
        ->and($echoed->json('request_id'))->toBe('01M4G06BJDY51YZX0PWRBQ8EBJ')
        ->and($minted->headers->get('X-Lxai-Request-Id'))->toMatch('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/')
        ->and($minted->json('request_id'))->toBe($minted->headers->get('X-Lxai-Request-Id'));
});
