<?php

declare(strict_types=1);

namespace App\Support\Json;

use InvalidArgumentException;
use stdClass;

/**
 * RFC 8785 (JSON Canonicalization Scheme) for the value types the fixture
 * schemas allow. Port of packaging/fixtures/lib/jcs.mjs; the two must agree
 * byte for byte, which tests/Unit/Support/JcsTest.php proves against the
 * frozen brief fixtures.
 *
 * Rules: object members sorted by UTF-16 code units, no whitespace, strings
 * escaped as JSON.stringify does (short escapes, lowercase \u00xx for other
 * control characters, no escaping of "/" or non-ASCII, U+2028/2029 literal),
 * numbers printed with the ES6 Number::toString algorithm (shortest
 * round-trip digits; integral floats without a fraction; exponent form only
 * for magnitudes >= 1e21 or < 1e-6).
 */
final class Jcs
{
    public static function canonicalize(mixed $value): string
    {
        if (is_array($value)) {
            return array_is_list($value)
                ? self::canonicalizeList($value)
                : self::canonicalizeObject($value);
        }

        if ($value instanceof stdClass) {
            return self::canonicalizeObject((array) $value);
        }

        if (is_string($value)) {
            return self::string($value);
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            return self::number($value);
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return 'null';
        }

        throw new InvalidArgumentException('JCS cannot canonicalize a value of type '.get_debug_type($value));
    }

    public static function sha256(mixed $value): string
    {
        return 'sha256:'.hash('sha256', self::canonicalize($value));
    }

    /** @param array<int, mixed> $list */
    private static function canonicalizeList(array $list): string
    {
        return '['.implode(',', array_map(self::canonicalize(...), $list)).']';
    }

    /** @param array<array-key, mixed> $object */
    private static function canonicalizeObject(array $object): string
    {
        $keys = array_map(strval(...), array_keys($object));

        usort($keys, static fn (string $a, string $b): int => strcmp(self::utf16($a), self::utf16($b)));

        $members = [];

        foreach ($keys as $key) {
            $members[] = self::string($key).':'.self::canonicalize($object[$key]);
        }

        return '{'.implode(',', $members).'}';
    }

    private static function utf16(string $value): string
    {
        return mb_convert_encoding($value, 'UTF-16BE', 'UTF-8');
    }

    private static function string(string $value): string
    {
        return json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR
        );
    }

    /**
     * ES6 Number::toString for finite doubles (RFC 8785 §3.2.2.3).
     */
    private static function number(float $value): string
    {
        if (! is_finite($value)) {
            throw new InvalidArgumentException('JCS cannot canonicalize NaN or Infinity');
        }

        if ($value === 0.0) {
            return '0';
        }

        $sign = $value < 0 ? '-' : '';
        [$digits, $n] = self::shortestDigits(abs($value));
        $k = strlen($digits);

        if ($k <= $n && $n <= 21) {
            return $sign.$digits.str_repeat('0', $n - $k);
        }

        if ($n > 0 && $n <= 21) {
            return $sign.substr($digits, 0, $n).'.'.substr($digits, $n);
        }

        if ($n > -6 && $n <= 0) {
            return $sign.'0.'.str_repeat('0', -$n).$digits;
        }

        $exponent = $n - 1;
        $exponentSign = $exponent < 0 ? '-' : '+';
        $mantissa = $k === 1 ? $digits : $digits[0].'.'.substr($digits, 1);

        return $sign.$mantissa.'e'.$exponentSign.abs($exponent);
    }

    /**
     * Shortest round-trip decimal digits of a positive double and the position
     * n of the decimal point such that value = 0.<digits> x 10^n.
     *
     * @return array{0: string, 1: int}
     */
    private static function shortestDigits(float $value): array
    {
        $repr = json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        $exponent = 0;

        if (str_contains($repr, 'e')) {
            [$repr, $exponentPart] = explode('e', $repr, 2);
            $exponent = (int) $exponentPart;
        }

        [$integerPart, $fractionPart] = str_contains($repr, '.') ? explode('.', $repr, 2) : [$repr, ''];

        $digits = ltrim($integerPart.$fractionPart, '0');
        $leadingZeros = strlen($integerPart.$fractionPart) - strlen($digits);
        $n = strlen($integerPart) - $leadingZeros + $exponent;
        $digits = rtrim($digits, '0');

        if ($digits === '') {
            throw new InvalidArgumentException('Unexpected zero in shortestDigits');
        }

        return [$digits, $n];
    }
}
