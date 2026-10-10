<?php

declare(strict_types=1);

namespace App\Briefs\Render;

use Carbon\CarbonImmutable;

/**
 * Maps the brief's BCP-47 `language` onto one of the four evaluated
 * locales (§4.2: sv, en, it, de) and formats timestamps for it. Formatting
 * is presentation (brief schema §6 "may differ"); the parity payload keeps
 * the RFC 3339 text.
 */
final class BriefLocale
{
    /** @var list<string> */
    public const array SUPPORTED = ['sv', 'en', 'it', 'de'];

    public const string FALLBACK = 'en';

    public static function resolve(string $languageTag): string
    {
        $primary = strtolower(explode('-', $languageTag, 2)[0]);

        return in_array($primary, self::SUPPORTED, true) ? $primary : self::FALLBACK;
    }

    /** Full date and time in the document's own offset, e.g. "torsdag 9 oktober 2026 kl. 05:30 (UTC+02:00)". */
    public static function dateTime(string $rfc3339, string $locale): string
    {
        $instant = CarbonImmutable::parse($rfc3339);

        return $instant->locale($locale)->isoFormat('LLLL').' ('.self::offsetLabel($rfc3339).')';
    }

    /** Shorter form for ranges, e.g. "8 oktober 2026 05:30". */
    public static function shortDateTime(string $rfc3339, string $locale): string
    {
        return CarbonImmutable::parse($rfc3339)->locale($locale)->isoFormat('LL HH:mm');
    }

    public static function period(string $start, string $end, string $locale): string
    {
        if ($start === $end) {
            return self::shortDateTime($start, $locale).' ('.self::offsetLabel($start).')';
        }

        return self::shortDateTime($start, $locale).' – '.self::shortDateTime($end, $locale).' ('.self::offsetLabel($end).')';
    }

    public static function percent(float|int $fraction): int
    {
        return (int) round($fraction * 100);
    }

    private static function offsetLabel(string $rfc3339): string
    {
        if (str_ends_with($rfc3339, 'Z')) {
            return 'UTC';
        }

        return 'UTC'.substr($rfc3339, -6);
    }
}
