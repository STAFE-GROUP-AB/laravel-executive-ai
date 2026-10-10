<?php

declare(strict_types=1);

namespace App\Integrations\Mail\Exceptions;

/**
 * Environmental reasons a provider call could not be served, as opposed to an
 * authorization problem (`AuthorizationRequiredException`) or a policy refusal
 * (`SendRefusedException`). Callers back off with jitter and keep the rest of the
 * product working (blueprint §5.6 "quota feedback", §9).
 */
enum MailUnavailableReason: string
{
    case Network = 'network';
    case Timeout = 'timeout';
    case ProviderError = 'provider_error';
    case RateLimited = 'rate_limited';
    case QuotaExceeded = 'quota_exceeded';
    case BridgeNotRunning = 'bridge_not_running';
    case CliNotInstalled = 'cli_not_installed';
    case CertificateRejected = 'certificate_rejected';
}
