<?php

declare(strict_types=1);

namespace App\Http\Controllers\Health;

/**
 * Both endpoints return `application/json; charset=utf-8` and `no-store`
 * (health contract §1).
 */
final class HealthHeaders
{
    /** @return array<string, string> */
    public static function all(): array
    {
        return [
            'Content-Type' => 'application/json; charset=utf-8',
            'Cache-Control' => 'no-store',
        ];
    }
}
