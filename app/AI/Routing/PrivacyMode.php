<?php

declare(strict_types=1);

namespace App\AI\Routing;

/**
 * Privacy mode of an AI run (blueprint §4; ADR-004 D4 and D5). The modes compose as a
 * conjunction when both restrictions apply; `Custom` means the user accepted the
 * provider's own terms with no additional routing restriction.
 */
enum PrivacyMode: string
{
    case LocalOnly = 'local_only';
    case EuRestricted = 'eu_restricted';
    case Custom = 'custom';
}
