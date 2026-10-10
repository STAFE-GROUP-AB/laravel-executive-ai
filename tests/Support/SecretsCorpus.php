<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Reads tests/Security/fixtures/secrets-corpus.json (ADR-003 D7). Every
 * value in it is synthetic; see the file's $comment.
 */
final class SecretsCorpus
{
    /** @var array<string, mixed>|null */
    private static ?array $corpus = null;

    /** @return array<string, mixed> */
    public static function load(): array
    {
        return self::$corpus ??= json_decode(
            (string) file_get_contents(dirname(__DIR__).'/Security/fixtures/secrets-corpus.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    }

    /** @return array<int, array{pattern: string, value: string, text?: string}> */
    public static function patternSecrets(): array
    {
        return self::load()['pattern_secrets'];
    }

    /** @return array<int, array{key: string, value: string}> */
    public static function keyedSecrets(): array
    {
        return self::load()['keyed_secrets'];
    }

    /** @return array<int, array{container: string, key: string, value: string}> */
    public static function highEntropyHeaderValues(): array
    {
        return self::load()['high_entropy_header_values'];
    }

    /** @return array<int, string> */
    public static function benign(): array
    {
        return self::load()['benign'];
    }

    /**
     * Every secret value in the corpus, for "zero matches in the sink" sweeps.
     *
     * @return array<int, string>
     */
    public static function allValues(): array
    {
        $values = [];

        foreach (self::patternSecrets() as $entry) {
            $values[] = $entry['value'];
        }

        foreach (self::keyedSecrets() as $entry) {
            $values[] = $entry['value'];
        }

        foreach (self::highEntropyHeaderValues() as $entry) {
            $values[] = $entry['value'];
        }

        return array_values(array_unique($values));
    }

    /** @return array<int, string> occurrences of corpus values found in $haystack */
    public static function leaksIn(string $haystack): array
    {
        return array_values(array_filter(self::allValues(), static fn (string $value): bool => str_contains($haystack, $value)));
    }

    /**
     * A log/exception message that embeds every pattern secret in prose, the
     * way a careless adapter would.
     */
    public static function proseWithEverySecret(): string
    {
        $lines = [];

        foreach (self::patternSecrets() as $entry) {
            $lines[] = 'provider rejected '.($entry['text'] ?? $entry['value']);
        }

        return implode(' | ', $lines);
    }
}
