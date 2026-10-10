<?php

declare(strict_types=1);

namespace App\Security\Secrets;

use Throwable;

/**
 * The single redaction implementation for every PHP sink (ADR-003 D7):
 * key-based, pattern-based, high-entropy values in header positions, plus
 * registered live values so an unknown format that the process fetched is
 * still caught. Used by RedactSecretsProcessor (logs), the failed-job
 * provider, and later by audit, AI-run and diagnostics persistence.
 */
final class Redactor
{
    private const int HIGH_ENTROPY_MIN_LENGTH = 32;

    private const float HIGH_ENTROPY_BITS_PER_CHAR = 3.5;

    /** @var array<int, string> */
    private array $registered = [];

    /** @var array<string, string> */
    private readonly array $patterns;

    /** @var array<int, string> */
    private readonly array $sensitiveKeys;

    /** @var array<int, string> */
    private readonly array $headerContainers;

    public function __construct()
    {
        $this->patterns = SecretPatterns::all();
        $this->sensitiveKeys = SecretPatterns::sensitiveKeys();
        $this->headerContainers = SecretPatterns::headerContainers();
    }

    /**
     * Register a live secret value: every later occurrence in any sink is
     * replaced whatever its format. Values shorter than 8 characters are
     * ignored to avoid shredding ordinary text.
     */
    public function register(#[\SensitiveParameter] string $secret): void
    {
        if (strlen($secret) < 8 || in_array($secret, $this->registered, true)) {
            return;
        }

        $this->registered[] = $secret;
    }

    public function redactString(string $text): string
    {
        if ($text === '') {
            return $text;
        }

        foreach ($this->registered as $secret) {
            $text = str_replace($secret, SecretPatterns::PLACEHOLDER, $text);
        }

        foreach ($this->patterns as $pattern) {
            $replaced = preg_replace_callback(
                $pattern,
                static fn (array $match): string => ($match['keep'] ?? '').SecretPatterns::PLACEHOLDER,
                $text
            );

            if (is_string($replaced)) {
                $text = $replaced;
            }
        }

        return $text;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public function redactArray(array $data, bool $insideHeaders = false): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            $normalisedKey = $this->normaliseKey($key);

            if ($this->isSensitiveKey($normalisedKey)) {
                $result[$key] = $value === null ? null : SecretPatterns::PLACEHOLDER;

                continue;
            }

            $result[$key] = $this->redactValue($value, $insideHeaders || in_array($normalisedKey, $this->headerContainers, true));
        }

        return $result;
    }

    /**
     * Structured, redacted representation of a throwable for log context:
     * never the live object, whose message would bypass every processor.
     *
     * @return array{class: string, message: string, code: int|string, file: string, line: int, trace: string}
     */
    public function describeThrowable(Throwable $exception): array
    {
        return [
            'class' => $exception::class,
            'message' => $this->redactString($exception->getMessage()),
            'code' => $exception->getCode(),
            'file' => basename($exception->getFile()),
            'line' => $exception->getLine(),
            'trace' => $this->redactString($exception->getTraceAsString()),
        ];
    }

    public function looksHighEntropy(string $value): bool
    {
        $length = strlen($value);

        if ($length < self::HIGH_ENTROPY_MIN_LENGTH || preg_match('/^[A-Za-z0-9+\/=_.~-]+$/', $value) !== 1) {
            return false;
        }

        $counts = count_chars($value, 1);
        $entropy = 0.0;

        foreach ($counts as $count) {
            $probability = $count / $length;
            $entropy -= $probability * log($probability, 2);
        }

        return $entropy >= self::HIGH_ENTROPY_BITS_PER_CHAR;
    }

    private function redactValue(mixed $value, bool $insideHeaders): mixed
    {
        if (is_string($value)) {
            if ($insideHeaders && $this->looksHighEntropy($value)) {
                return SecretPatterns::PLACEHOLDER;
            }

            return $this->redactString($value);
        }

        if (is_array($value)) {
            return $this->redactArray($value, $insideHeaders);
        }

        if ($value instanceof Throwable) {
            return $this->describeThrowable($value);
        }

        if ($value instanceof \Stringable) {
            return $this->redactString((string) $value);
        }

        return $value;
    }

    private function normaliseKey(int|string $key): string
    {
        return str_replace('-', '_', strtolower((string) $key));
    }

    private function isSensitiveKey(string $key): bool
    {
        if (in_array($key, $this->sensitiveKeys, true)) {
            return true;
        }

        return str_ends_with($key, '_token')
            || str_ends_with($key, '_secret')
            || str_ends_with($key, '_password')
            || str_contains($key, 'password');
    }
}
