<?php

declare(strict_types=1);

namespace App\AI\Providers\Dto;

/**
 * The ADR-004 D2 error taxonomy. The first six map one-to-one from the SDK's
 * exception classes (`ProviderConnectionException`, `ProviderOverloadedException`,
 * `RateLimitedException`, `InsufficientCreditsException`, `StreamErrorException`,
 * `NoSuchToolException`); the rest are ours.
 */
enum AiErrorKind: string
{
    case Connection = 'connection';
    case Overloaded = 'overloaded';
    case RateLimited = 'rate_limited';
    case InsufficientCredits = 'insufficient_credits';
    case StreamError = 'stream_error';
    case NoSuchTool = 'no_such_tool';
    case CapabilityMissing = 'capability_missing';
    case EgressBlocked = 'egress_blocked';
    case PolicyDenied = 'policy_denied';
    case StructuredOutputInvalid = 'structured_output_invalid';
    case Cancelled = 'cancelled';
    case Unknown = 'unknown';
}
