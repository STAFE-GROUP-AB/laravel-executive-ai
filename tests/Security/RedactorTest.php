<?php

declare(strict_types=1);

use App\Security\Secrets\Redactor;
use App\Security\Secrets\SecretPatterns;
use Tests\Support\SecretsCorpus;

/*
 * ADR-003 D7: the Redactor is the single implementation behind every sink.
 * Every entry of tests/Security/fixtures/secrets-corpus.json must disappear;
 * every benign value must survive untouched.
 */

it('redacts every pattern secret in the corpus', function (array $entry): void {
    $redactor = app(Redactor::class);
    $text = 'before '.($entry['text'] ?? $entry['value']).' after';

    $redacted = $redactor->redactString($text);

    expect($redacted)->not->toContain($entry['value'])
        ->toContain(SecretPatterns::PLACEHOLDER)
        ->toStartWith('before ')
        ->toEndWith(' after');
})->with(fn () => array_map(fn (array $entry): array => [$entry], SecretsCorpus::patternSecrets()));

it('has a corpus example for every pattern it ships', function (): void {
    $covered = array_unique(array_column(SecretsCorpus::patternSecrets(), 'pattern'));

    expect(array_keys(SecretPatterns::all()))->each->toBeIn($covered);
});

it('redacts keyed values wholesale whatever they look like', function (array $entry): void {
    $redacted = app(Redactor::class)->redactArray([$entry['key'] => $entry['value'], 'kept' => 'plain']);

    expect($redacted[$entry['key']])->toBe(SecretPatterns::PLACEHOLDER)
        ->and($redacted['kept'])->toBe('plain');
})->with(fn () => array_map(fn (array $entry): array => [$entry], SecretsCorpus::keyedSecrets()));

it('redacts high-entropy values in header positions but not elsewhere', function (array $entry): void {
    $redactor = app(Redactor::class);

    $redacted = $redactor->redactArray([$entry['container'] => [$entry['key'] => $entry['value'], 'Accept' => 'application/json']]);

    expect($redacted[$entry['container']][$entry['key']])->toBe(SecretPatterns::PLACEHOLDER)
        ->and($redacted[$entry['container']]['Accept'])->toBe('application/json')
        ->and($redactor->redactArray(['note' => $entry['value']])['note'])->toBe($entry['value']);
})->with(fn () => array_map(fn (array $entry): array => [$entry], SecretsCorpus::highEntropyHeaderValues()));

it('leaves benign values untouched', function (string $value): void {
    $redactor = app(Redactor::class);

    expect($redactor->redactString($value))->toBe($value)
        ->and($redactor->redactArray(['content_hash' => $value, 'idempotency_key' => $value]))->toBe(['content_hash' => $value, 'idempotency_key' => $value]);
})->with(fn () => SecretsCorpus::benign());

it('redacts registered live values of unknown shape', function (): void {
    $redactor = new Redactor;
    $redactor->register('wholly-unknown-format-value-77');

    expect($redactor->redactString('got wholly-unknown-format-value-77 back'))->toBe('got '.SecretPatterns::PLACEHOLDER.' back')
        ->and($redactor->redactString('short'))->toBe('short');
});

it('describes a throwable without leaking its message or trace', function (): void {
    $value = SecretsCorpus::patternSecrets()[0]['value'];
    $described = app(Redactor::class)->describeThrowable(new RuntimeException("rejected {$value}"));

    expect($described['class'])->toBe(RuntimeException::class)
        ->and($described['message'])->not->toContain($value)
        ->and(json_encode($described, JSON_THROW_ON_ERROR))->not->toContain($value);
});

it('recurses through nested context', function (): void {
    $value = SecretsCorpus::patternSecrets()[2]['value'];
    $redacted = app(Redactor::class)->redactArray(['request' => ['url' => "https://api.github.com/user?access_token={$value}", 'headers' => ['Authorization' => "token {$value}"]]]);

    expect(json_encode($redacted, JSON_THROW_ON_ERROR))->not->toContain($value)
        ->and($redacted['request']['url'])->toStartWith('https://api.github.com/user?access_token=');
});
