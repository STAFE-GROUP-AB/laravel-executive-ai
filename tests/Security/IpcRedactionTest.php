<?php

declare(strict_types=1);

use App\Models\AuditEvent;
use App\Security\Ipc\OAuth\OAuthCallbackHandler;
use App\Security\Secrets\Redactor;
use App\Security\Secrets\SecretPatterns;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\File;
use Tests\Support\Ipc\FakeOAuthCallbackHandler;
use Tests\Support\Ipc\IpcClient;
use Tests\Support\PwaFixtures;
use Tests\Support\SecretsCorpus;

/*
 * IPC contract §10 / §12 `IpcRedactionTest` (ADR-003 D7 rows 1, 2, 8;
 * threat model T-10, T-70): the corpus IPC tokens (`lxai1.…`, `lxai1e.…`)
 * and real K_test-derived ones never reach a log from an exception
 * message; the oauth/callback body never appears in any sink; the request
 * log line and `ipc.rejected` never carry the Authorization, signature or
 * elevation header values.
 */

uses(DatabaseTransactions::class);

function ipcLogDirectory(): string
{
    $directory = sys_get_temp_dir().'/lea-ipc-redaction-'.uniqid();
    mkdir($directory, 0700, true);
    config(['logging.channels.single.path' => $directory.'/laravel.log', 'logging.channels.stack.channels' => ['single']]);

    return $directory;
}

function ipcLogContents(string $directory): string
{
    $file = $directory.'/laravel.log';

    return file_exists($file) ? (string) file_get_contents($file) : '';
}

it('ships corpus examples for both IPC token formats', function (): void {
    $patterns = array_column(SecretsCorpus::patternSecrets(), 'value', 'pattern');

    expect(SecretPatterns::all())->toHaveKeys(['lea_ipc_session_token', 'lea_ipc_elevation_token'])
        ->and($patterns)->toHaveKeys(['lea_ipc_session_token', 'lea_ipc_elevation_token'])
        ->and(array_column(SecretsCorpus::patternSecrets(), 'value'))->toContain('lxai1e.remote-access-enable.RkFLRUZBS0VGQUtFRkFLRQ.1794240000.0123456789abcdef0123456789abcdef');
});

it('redacts corpus and live K_test tokens and elevations from an exception message before it is logged', function (): void {
    $directory = ipcLogDirectory();
    $liveToken = IpcClient::token();
    $liveElevation = IpcClient::elevation('remote-access-enable', IpcClient::nonce());
    $corpus = implode(' ', array_map(fn (array $entry): string => $entry['value'], array_filter(SecretsCorpus::patternSecrets(), fn (array $entry): bool => str_starts_with($entry['pattern'], 'lea_ipc_'))));

    try {
        report(new RuntimeException("ipc rejected Bearer {$liveToken} with elevation {$liveElevation} and corpus {$corpus}"));
        $written = ipcLogContents($directory);
    } finally {
        File::deleteDirectory($directory);
    }

    expect($written)->toContain('ipc rejected')
        ->and($written)->not->toContain($liveToken)
        ->and($written)->not->toContain($liveElevation)
        ->and($written)->not->toContain(substr($liveToken, -32))
        ->and(SecretsCorpus::leaksIn($written))->toBe([]);
});

it('keeps the oauth/callback body out of every sink even when the handler embeds it in an exception', function (): void {
    $directory = ipcLogDirectory();
    PwaFixtures::owner();
    $state = 'st_FAKE01M4G0S7X2K9P4R6T8V1W3Y5Z7';
    $code = '4/0AX4XfW-FAKE-authorization-code-0000-TEST';

    app()->instance(OAuthCallbackHandler::class, new FakeOAuthCallbackHandler(function (string $state, string $code): never {
        throw new RuntimeException("token exchange failed for state={$state} code={$code}");
    }));

    try {
        $response = IpcClient::send('POST', '/ipc/v1/oauth/callback', ['state' => $state, 'code' => $code]);
        $written = ipcLogContents($directory);
    } finally {
        File::deleteDirectory($directory);
    }

    $audit = AuditEvent::query()->get()->map(fn (AuditEvent $event): string => json_encode($event->getAttributes(), JSON_THROW_ON_ERROR))->implode("\n");

    expect($response->getStatusCode())->toBe(500)
        ->and($response->json('error.code'))->toBe('internal')
        ->and((string) $response->getContent())->not->toContain($code)
        ->and((string) $response->getContent())->not->toContain($state)
        ->and($written)->toContain('OAuth callback exchange failed')
        ->and($written)->not->toContain($code)
        ->and($written)->not->toContain($state)
        ->and($audit)->not->toContain($code)
        ->and($audit)->not->toContain($state)
        ->and(app(Redactor::class)->redactString("later log line mentioning {$code} and {$state}"))->not->toContain($code);
});

it('writes a request log line without Authorization, signature or elevation values', function (): void {
    $directory = ipcLogDirectory();
    PwaFixtures::owner();
    $token = IpcClient::token();
    $nonce = IpcClient::nonce();
    $elevation = IpcClient::elevation('remote-access-enable', $nonce);

    try {
        IpcClient::send('POST', '/ipc/v1/remote-access/enable', ['confirmed_origin' => 'https://my-mac.example-tailnet.ts.net', 'replace_existing' => false], ['token' => $token, 'nonce' => $nonce, 'elevation' => $elevation]);
        $written = ipcLogContents($directory);
    } finally {
        File::deleteDirectory($directory);
    }

    expect($written)->toContain('ipc.request')
        ->and($written)->toContain('/ipc/v1/remote-access/enable')
        ->and($written)->not->toContain($token)
        ->and($written)->not->toContain($elevation)
        ->and($written)->not->toContain('v1=')
        ->and($written)->not->toContain('confirmed_origin');
});

it('records ipc.rejected without the presented credentials', function (): void {
    $token = IpcClient::token();
    $signature = 'v1='.str_repeat('f', 64);

    IpcClient::send('GET', '/ipc/v1/health', null, ['token' => $token, 'signature' => $signature])->assertStatus(401);

    $event = AuditEvent::query()->where('action', 'ipc.rejected')->latest('occurred_at')->firstOrFail();
    $row = json_encode($event->getAttributes(), JSON_THROW_ON_ERROR);

    expect($event->context['reason'])->toBe('signature_invalid')
        ->and($row)->not->toContain($token)
        ->and($row)->not->toContain($signature)
        ->and($row)->not->toContain(substr($token, -32));
});
