<?php

declare(strict_types=1);

namespace App\Briefs\Render;

use Carbon\CarbonImmutable;
use DateTimeZone;

/**
 * Renders a stored UTC instant back in the brief's own UTC offset, exactly as
 * BriefDocumentMapper exports it ("Z" stays "Z"), so a list row shows the same
 * generated_at text as the exported document without exporting every version.
 */
final class BriefTime
{
    public static function inBriefZone(CarbonImmutable $instant, string $offset): CarbonImmutable
    {
        if ($offset === 'Z') {
            return $instant->utc();
        }

        return $instant->setTimezone(new DateTimeZone($offset));
    }

    public static function rfc3339(CarbonImmutable $instant, string $offset): string
    {
        if ($offset === 'Z') {
            return $instant->utc()->format('Y-m-d\TH:i:s\Z');
        }

        return self::inBriefZone($instant, $offset)->format('Y-m-d\TH:i:sP');
    }
}
