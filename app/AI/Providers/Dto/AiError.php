<?php

declare(strict_types=1);

namespace App\AI\Providers\Dto;

/**
 * A classified provider failure (ADR-004 D2 `ErrorMapper`). `message` is already
 * scrubbed by `Security/Redaction`; raw vendor payloads never reach this object.
 */
final readonly class AiError
{
    public function __construct(
        public AiErrorKind $kind,
        public string $message,
        public bool $retryable,
    ) {}
}
