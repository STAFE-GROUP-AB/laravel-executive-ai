<?php

declare(strict_types=1);

namespace App\Support\Health;

/**
 * Health contract §4.
 */
enum BriefState: string
{
    case None = 'none';
    case Fresh = 'fresh';
    case Stale = 'stale';
    case Error = 'error';
}
