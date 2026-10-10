<?php

declare(strict_types=1);

use App\Security\Ipc\IpcClient;
use App\Security\Ipc\IpcKey;
use App\Security\Ipc\IpcSigner;
use App\Support\Json\Jcs;

/*
 * IPC contract §4.6: the PHP port of packaging/fixtures/ipc-signing.mjs
 * must agree with the recorded fixtures byte for byte. These vectors are
 * copied from packaging/fixtures/ipc/*.json (signed with K_test).
 */

const IPC_TEST_SESSION = '01M4G0QK2T6Z5P9Y3R7W1A8C4E';

function ipcFixture(string $name): array
{
    return json_decode((string) file_get_contents(base_path("packaging/fixtures/ipc/{$name}.json")), true, 512, JSON_THROW_ON_ERROR);
}

it('derives the fixture menubar token mac from K_test', function (): void {
    expect(IpcSigner::tokenMac(IpcKey::forTesting(), IpcClient::Menubar, IPC_TEST_SESSION, 1794240000))->toBe('f8c50b991bd35d59ed0fa74ffdfe4bbf')
        ->and(IpcSigner::makeToken(IpcKey::forTesting(), IpcClient::Menubar, IPC_TEST_SESSION, 1794240000))
        ->toBe('lxai1.menubar.01M4G0QK2T6Z5P9Y3R7W1A8C4E.1794240000.f8c50b991bd35d59ed0fa74ffdfe4bbf');
});

it('derives the fixture supervisor token mac from K_test', function (): void {
    expect(IpcSigner::tokenMac(IpcKey::forTesting(), IpcClient::Supervisor, IPC_TEST_SESSION, 1794240000))->toBe('145d37136c40c926d50a42d9765a1ec6');
});

it('reproduces every recorded request signature', function (string $file): void {
    $fixture = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    $request = $fixture['request'];
    $body = $request['body'] === null ? '' : Jcs::canonicalize($request['body'] === [] ? new stdClass : $request['body']);

    $signature = IpcSigner::signRequest(
        IpcSigner::signingKey(IpcKey::forTesting(), IpcClient::from(explode('.', substr($request['headers']['Authorization'], 7))[1]), IPC_TEST_SESSION),
        $request['method'],
        $request['path'],
        $request['headers']['X-Lxai-Timestamp'],
        $request['headers']['X-Lxai-Nonce'],
        $body,
    );

    $recordedIsInvalidByDesign = ($fixture['response']['body']['error']['code'] ?? null) === 'signature_invalid';

    $recordedIsInvalidByDesign
        ? expect($signature)->not->toBe($request['headers']['X-Lxai-Signature'])
        : expect($signature)->toBe($request['headers']['X-Lxai-Signature']);
})->with(fn () => glob(dirname(__DIR__, 4).'/packaging/fixtures/ipc/*.json') ?: []);

it('reproduces every recorded response signature', function (string $file): void {
    $fixture = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    $request = $fixture['request'];
    $response = $fixture['response'];
    $client = IpcClient::from(explode('.', substr($request['headers']['Authorization'], 7))[1]);
    $body = $response['body'] === null ? '' : Jcs::canonicalize($response['body']);

    $signature = IpcSigner::signResponse(IpcSigner::signingKey(IpcKey::forTesting(), $client, IPC_TEST_SESSION), $response['status'], $request['headers']['X-Lxai-Nonce'], $body);

    if (! isset($response['headers']['X-Lxai-Signature'])) {
        expect($response['body']['error']['code'])->toBeIn(['origin_rejected', 'version_unsupported', 'session_invalid']);

        return;
    }

    expect($signature)->toBe($response['headers']['X-Lxai-Signature']);
})->with(fn () => glob(dirname(__DIR__, 4).'/packaging/fixtures/ipc/*.json') ?: []);

it('reproduces the recorded elevation for remote-access/enable', function (): void {
    $fixture = ipcFixture('remote-access-enable');

    expect(IpcSigner::makeElevation(IpcKey::forTesting(), IPC_TEST_SESSION, 'remote-access-enable', $fixture['request']['headers']['X-Lxai-Nonce'], 1791648180))
        ->toBe($fixture['request']['headers']['X-Lxai-Elevation']);
});

it('hashes the empty body as SHA-256 of the empty string', function (): void {
    expect(IpcSigner::canonicalRequest('GET', '/ipc/v1/health', '1', 'n', ''))
        ->toEndWith("\ne3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855");
});

it('refuses the all-zero key outside forTesting and never serialises a key', function (): void {
    expect(fn () => IpcKey::fromBytes(str_repeat("\0", 32)))->toThrow(InvalidArgumentException::class)
        ->and(fn () => IpcKey::fromBytes('short'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => serialize(IpcKey::forTesting()))->toThrow(LogicException::class)
        ->and(fn () => (string) IpcKey::forTesting())->toThrow(LogicException::class)
        ->and(print_r(IpcKey::forTesting(), true))->not->toContain("\0")
        ->and(strlen(IpcKey::fromBytes(random_bytes(32))->bytes()))->toBe(32);
});
