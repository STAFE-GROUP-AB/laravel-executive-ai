<?php

declare(strict_types=1);

namespace App\AI\Providers\Exceptions;

/**
 * The provider could not serve the request: `connection`, `overloaded`,
 * `rate_limited`, `insufficient_credits` or `stream_error`. The caller may retry the
 * *same* provider after the circuit breaker allows it (ADR-004 D7); it must never
 * substitute another provider or model (D2 "no failover").
 */
final class ProviderUnavailableException extends AiProviderException {}
