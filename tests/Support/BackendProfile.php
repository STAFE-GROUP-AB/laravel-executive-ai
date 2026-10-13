<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * ADR-002 D1/D10: the contract suite runs once per profile. The `server` leg
 * needs a real MySQL 8.4 connection and skips itself when none is configured, so
 * a developer without MySQL stays green.
 */
final class BackendProfile
{
    public static function serverWithoutMysql(): bool
    {
        return config('lea.backend_profile') === 'server' && ! self::isMysql();
    }

    public static function isMysql(): bool
    {
        return in_array(config('database.default'), ['mysql', 'mariadb'], true);
    }

    public static function isSqlite(): bool
    {
        return config('database.default') === 'sqlite';
    }
}
