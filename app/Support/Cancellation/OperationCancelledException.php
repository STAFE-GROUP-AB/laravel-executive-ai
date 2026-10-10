<?php

declare(strict_types=1);

namespace App\Support\Cancellation;

use RuntimeException;

/**
 * Raised by `CancellationToken::throwIfCancelled()`. Callers that record outcomes
 * (`ai_runs`, `tool_runs`, `browser_sessions`) map it to the `cancelled` outcome,
 * never to a generic failure (ADR-004 D2 error taxonomy).
 */
final class OperationCancelledException extends RuntimeException
{
    public function __construct(?string $reason = null)
    {
        parent::__construct($reason ?? 'The operation was cancelled.');
    }
}
