<?php

declare(strict_types=1);

namespace App\Support\Install\Tunnel;

/**
 * The seven mutually exclusive states `TunnelProvider::inspect()` can report
 * (ADR-006 D2). Only `Ready` may be configured; `Drifted` only after the user
 * confirmed "Replace"; `FunnelDetected` can never be configured and offers only
 * removal.
 */
enum TunnelState: string
{
    case NotInstalled = 'not_installed';
    case NotLoggedIn = 'not_logged_in';
    case HttpsNotEnabled = 'https_not_enabled';
    case Ready = 'ready';
    case Active = 'active';
    case Drifted = 'drifted';
    case FunnelDetected = 'funnel_detected';

    public function canConfigure(): bool
    {
        return $this === self::Ready || $this === self::Drifted;
    }
}
