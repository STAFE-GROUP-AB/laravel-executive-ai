<?php

declare(strict_types=1);

namespace App\Security\Authorization\Devices;

/**
 * A short, human label for a browser from its User-Agent header: platform
 * and browser family only, never the full string, which is kept on the row
 * for the owner's own inspection. Good enough to tell "Safari on iPhone"
 * from "Chrome on Mac"; not a fingerprinting library.
 */
final class DeviceLabel
{
    private const array PLATFORMS = [
        'iPhone' => 'iPhone',
        'iPad' => 'iPad',
        'Macintosh' => 'Mac',
        'Android' => 'Android',
        'Windows' => 'Windows',
        'CrOS' => 'ChromeOS',
        'Linux' => 'Linux',
    ];

    private const array BROWSERS = [
        'Edg/' => 'Edge',
        'OPR/' => 'Opera',
        'Firefox/' => 'Firefox',
        'CriOS/' => 'Chrome',
        'FxiOS/' => 'Firefox',
        'Chrome/' => 'Chrome',
        'Safari/' => 'Safari',
    ];

    public static function fromUserAgent(?string $userAgent): string
    {
        $userAgent = (string) $userAgent;

        if (trim($userAgent) === '') {
            return __('security.devices.unknown_device');
        }

        $platform = self::first($userAgent, self::PLATFORMS);
        $browser = self::first($userAgent, self::BROWSERS);

        if (str_contains($userAgent, 'LaravelExecutiveAI')) {
            $browser = __('security.devices.mac_app');
        }

        if ($platform !== null && $browser !== null) {
            return "{$browser} · {$platform}";
        }

        return $browser ?? $platform ?? __('security.devices.unknown_device');
    }

    /** @param array<string, string> $needles */
    private static function first(string $userAgent, array $needles): ?string
    {
        foreach ($needles as $needle => $label) {
            if (str_contains($userAgent, $needle)) {
                return $label;
            }
        }

        return null;
    }
}
