<?php

declare(strict_types=1);

namespace App\AI\Providers\Exceptions;

use App\AI\Providers\Dto\AiError;
use App\AI\Providers\Dto\AiErrorKind;

/**
 * A request under `LocalOnly` targeted a host outside the loopback allowlist and was
 * stopped before any socket opened (ADR-004 D4). Deliberately not retryable and
 * deliberately not a failover trigger.
 */
final class EgressBlockedException extends AiProviderException
{
    public function __construct(
        public readonly string $blockedHost,
    ) {
        parent::__construct(new AiError(
            kind: AiErrorKind::EgressBlocked,
            message: 'Local Only mode blocked a request to a host outside this Mac.',
            retryable: false,
        ));
    }
}
