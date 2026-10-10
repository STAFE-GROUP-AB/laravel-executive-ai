<?php

declare(strict_types=1);

namespace App\AI\Providers;

/**
 * Where a ModelCapabilities record came from, in ADR-004 D3 precedence order.
 */
enum CapabilitySource: string
{
    case Registry = 'registry';
    case Probe = 'probe';
    case UserOverride = 'user_override';
    case Unknown = 'unknown';
}
