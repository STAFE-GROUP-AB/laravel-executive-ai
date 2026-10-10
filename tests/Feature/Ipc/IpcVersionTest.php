<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\CoreReadyState;
use Tests\Support\Ipc\IpcClient;

/*
 * IPC contract §12 `IpcVersionTest` / §5.1 / S1-08 criterion 3: a missing,
 * `0`, `2` or otherwise unsupported X-Lxai-Ipc-Version fails closed with
 * 426, `supported_versions: [1]` and the repair hint — never a silent
 * degradation — and the response is unsigned because no key was derived.
 */

uses(DatabaseTransactions::class);

beforeEach(function (): void {
    CoreReadyState::create();
});

it('fails closed on a missing or unsupported wire major', function (?string $version): void {
    $response = IpcClient::send('GET', '/ipc/v1/health', null, ['version' => $version]);

    $response->assertStatus(426);
    expect($response->json('ok'))->toBeFalse()
        ->and($response->json('error.code'))->toBe('version_unsupported')
        ->and($response->json('error.message'))->toBe('This IPC version is not supported by the installed core.')
        ->and($response->json('error.hint'))->toBe('Update or repair Laravel Executive AI (Settings → Updates → Repair).')
        ->and($response->json('error.supported_versions'))->toBe([1])
        ->and($response->json('error.retry_after_seconds'))->toBeNull()
        ->and($response->headers->has('X-Lxai-Signature'))->toBeFalse()
        ->and($response->headers->get('X-Lxai-Ipc-Version'))->toBe('1');
})->with([
    'missing' => null,
    'zero' => '0',
    'two' => '2',
    'three' => '3',
    'semver instead of major' => '1.0.0',
    'leading zero' => '01',
    'text' => 'one',
]);

it('accepts the current major on every route', function (string $path): void {
    IpcClient::send('GET', $path, null, ['version' => '1'])->assertOk();
})->with(['/ipc/v1/health', '/health/ready']);

it('checks the version before the token so an old client learns to update even with a dead token', function (): void {
    $response = IpcClient::send('GET', '/ipc/v1/health', null, ['version' => '2', 'token' => 'lxai1.garbage']);

    $response->assertStatus(426);
    expect($response->json('error.supported_versions'))->toBe([1]);
});

it('requires the version header on /health/ready but not on /health/live', function (): void {
    IpcClient::send('GET', '/health/ready', null, ['version' => null])->assertStatus(426);
    IpcClient::send('GET', '/health/live', null, ['version' => null, 'token' => null])->assertOk();
});
