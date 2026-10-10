<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Tests\Support\SecretsCorpus;

use function Pest\Laravel\get;

/*
 * ADR-003 D7 row 2 / threat model T-11: an exception whose message and
 * context carry corpus values leaves nothing behind in the log, whether it
 * is reported directly or rendered from a request.
 */

function exceptionLogDirectory(): string
{
    $directory = sys_get_temp_dir().'/lea-exception-redaction-'.uniqid();
    mkdir($directory, 0700, true);
    config(['logging.channels.single.path' => $directory.'/laravel.log', 'logging.channels.stack.channels' => ['single']]);

    return $directory;
}

function logContents(string $directory): string
{
    $file = $directory.'/laravel.log';

    return file_exists($file) ? (string) file_get_contents($file) : '';
}

it('redacts a reported exception message and context', function (): void {
    $directory = exceptionLogDirectory();
    $value = SecretsCorpus::patternSecrets()[1]['value'];

    try {
        report(new RuntimeException("Anthropic rejected key {$value} for refresh_token=".SecretsCorpus::keyedSecrets()[6]['value']));
        $written = logContents($directory);
    } finally {
        File::deleteDirectory($directory);
    }

    expect($written)->toContain('Anthropic rejected key')
        ->and(SecretsCorpus::leaksIn($written))->toBe([]);
});

it('redacts an exception thrown while handling a request and never renders it in production mode', function (): void {
    $directory = exceptionLogDirectory();
    config(['app.debug' => false]);
    $prose = SecretsCorpus::proseWithEverySecret();

    Route::get('/_test/throws-with-secrets', function () use ($prose): never {
        throw new RuntimeException("sync failed: {$prose}");
    });

    try {
        $response = get('/_test/throws-with-secrets');
        $written = logContents($directory);
    } finally {
        File::deleteDirectory($directory);
    }

    $response->assertStatus(500);

    expect(SecretsCorpus::leaksIn($response->getContent()))->toBe([])
        ->and(SecretsCorpus::leaksIn($written))->toBe([])
        ->and($written)->toContain('sync failed:');
});

it('redacts a JSON-rendered exception on the loopback API surface', function (): void {
    config(['app.debug' => true]);
    $value = SecretsCorpus::patternSecrets()[0]['value'];

    Route::get('/health/_test-throws', function () use ($value): never {
        throw new RuntimeException("provider said {$value}");
    });

    $response = get('/health/_test-throws');

    $response->assertStatus(500);
    expect(SecretsCorpus::leaksIn($response->getContent()))->toBe([]);
})->todo('#36 (S1-08): debug-mode JSON rendering still echoes the raw message; the packaged build runs APP_DEBUG=false (PackagedConfigTest) and S1-08 wraps IPC/HTTP exceptions (ADR-003 D7 row 2)');
