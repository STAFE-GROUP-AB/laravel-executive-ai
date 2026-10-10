<?php

declare(strict_types=1);

namespace App\Support\Health;

/**
 * Health contract §3: not_configured -> configured_untested -> ready, with
 * error reachable from both configured states. ai_ready is true iff Ready.
 */
enum AiState: string
{
    case NotConfigured = 'not_configured';
    case ConfiguredUntested = 'configured_untested';
    case Ready = 'ready';
    case Error = 'error';
}
