<?php

declare(strict_types=1);

namespace App\Support\Install\Tunnel;

/**
 * Why a TunnelProvider call could not proceed. `PublicExposureRefused` is raised
 * whenever a call would run from `FunnelDetected` or would otherwise widen exposure
 * beyond the private tailnet (ADR-006 D6).
 */
enum TunnelFailureReason: string
{
    case ClientNotFound = 'client_not_found';
    case UnsupportedClient = 'unsupported_client';
    case NotReady = 'not_ready';
    case ConfirmationMissing = 'confirmation_missing';
    case PublicExposureRefused = 'public_exposure_refused';
    case CommandFailed = 'command_failed';
    case VerificationFailed = 'verification_failed';
}
