<?php

declare(strict_types=1);

use App\Security\Secrets\SecretPatterns;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Tests\Support\SecretsCorpus;

/*
 * ADR-003 D7 row 1 / threat model T-10: the corpus logged through every
 * file-backed channel never reaches the file.
 */

function scratchLogDirectory(): string
{
    $directory = sys_get_temp_dir().'/lea-log-redaction-'.uniqid();
    mkdir($directory, 0700, true);

    return $directory;
}

function readAllLogs(string $directory): string
{
    return implode("\n", array_map(fn (string $file): string => (string) file_get_contents($file), File::files($directory) === [] ? [] : array_map(fn ($file) => $file->getPathname(), File::files($directory))));
}

it('writes no corpus value through the file channels', function (string $channel): void {
    $directory = scratchLogDirectory();
    config(["logging.channels.{$channel}.path" => $directory.'/laravel.log']);

    $context = ['password' => 'correct-horse-fake-battery-0001', 'headers' => []];

    foreach (SecretsCorpus::keyedSecrets() as $entry) {
        $context[$entry['key']] = $entry['value'];
    }

    foreach (SecretsCorpus::highEntropyHeaderValues() as $entry) {
        $context['headers'][$entry['key']] = $entry['value'];
    }

    $context['nested'] = ['message' => SecretsCorpus::proseWithEverySecret()];

    try {
        Log::channel($channel)->error(SecretsCorpus::proseWithEverySecret(), $context);
        Log::channel($channel)->info('benign '.implode(' ', SecretsCorpus::benign()));

        $written = readAllLogs($directory);
    } finally {
        File::deleteDirectory($directory);
    }

    expect($written)->not->toBe('')
        ->and(SecretsCorpus::leaksIn($written))->toBe([])
        ->and($written)->toContain(SecretPatterns::PLACEHOLDER);

    foreach (SecretsCorpus::benign() as $benign) {
        expect($written)->toContain($benign);
    }
})->with(['single', 'daily', 'monthly']);

it('redacts through the default stack channel', function (): void {
    $directory = scratchLogDirectory();
    config(['logging.channels.single.path' => $directory.'/laravel.log', 'logging.channels.stack.channels' => ['single']]);

    try {
        Log::warning('refresh failed', ['refresh_token' => SecretsCorpus::keyedSecrets()[6]['value'], 'detail' => SecretsCorpus::proseWithEverySecret()]);
        $written = readAllLogs($directory);
    } finally {
        File::deleteDirectory($directory);
    }

    expect(SecretsCorpus::leaksIn($written))->toBe([])
        ->and($written)->toContain('refresh failed');
});
