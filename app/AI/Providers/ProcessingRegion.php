<?php

declare(strict_types=1);

namespace App\AI\Providers;

/**
 * Contracted inference region of a provider account (ADR-004 D5). `Unknown` is
 * the default and is never eligible under `EU_RESTRICTED`.
 */
enum ProcessingRegion: string
{
    case Eu = 'EU';
    case EeaPlusCh = 'EEA+CH';
    case Us = 'US';
    case Global = 'GLOBAL';
    case Unknown = 'UNKNOWN';
}
