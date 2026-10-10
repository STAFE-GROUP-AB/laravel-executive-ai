<?php

declare(strict_types=1);

namespace App\AI\Providers\Exceptions;

/**
 * The model's output did not satisfy the request's `outputSchema`. Surfaced as a
 * failure, never silently parsed from free text (ADR-004 D2 row 4, D3 forbidden
 * downgrades).
 */
final class InvalidStructuredOutputException extends AiProviderException {}
