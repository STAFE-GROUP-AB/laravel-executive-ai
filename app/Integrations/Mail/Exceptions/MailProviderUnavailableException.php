<?php

declare(strict_types=1);

namespace App\Integrations\Mail\Exceptions;

use DateTimeImmutable;
use RuntimeException;

/**
 * The provider, Bridge or CLI could not serve the call for an environmental reason.
 * The connection stays configured; the sync scheduler retries the *same* connection
 * after `retryAfter` (or its own exponential-jitter backoff when null). The message
 * is user-facing and never contains the provider's response body, a URL with a
 * token, or a hostname taken from external text (ADR-003 D7 row 2).
 */
final class MailProviderUnavailableException extends RuntimeException
{
    public function __construct(
        public readonly MailUnavailableReason $reason,
        string $message,
        public readonly ?string $connectionId = null,
        public readonly ?DateTimeImmutable $retryAfter = null,
    ) {
        parent::__construct($message);
    }
}
