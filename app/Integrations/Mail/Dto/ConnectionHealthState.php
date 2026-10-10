<?php

declare(strict_types=1);

namespace App\Integrations\Mail\Dto;

/**
 * The states `EmailConnector::verify()` can report (blueprint §5.6 acceptance
 * criterion 5: token expiry, revocation, network loss and changed passwords must
 * surface in the UI with a working Reconnect). Like `TunnelState`, these are states,
 * not errors: `verify()` never throws for any of them.
 */
enum ConnectionHealthState: string
{
    case Healthy = 'healthy';
    case Degraded = 'degraded';
    case RateLimited = 'rate_limited';
    case AuthorizationRequired = 'authorization_required';
    case Unavailable = 'unavailable';
    case Disconnected = 'disconnected';

    /**
     * Whether sync and send may proceed right now.
     */
    public function isUsable(): bool
    {
        return $this === self::Healthy || $this === self::Degraded;
    }

    /**
     * Whether the UI should offer `Reconnect` / `Reauthorize`.
     */
    public function needsReauthorization(): bool
    {
        return $this === self::AuthorizationRequired;
    }
}
