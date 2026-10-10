<?php

declare(strict_types=1);

namespace App\AI\Providers\Dto;

/**
 * Binary input for vision-capable models, held in memory only. Redacted automation
 * screenshots travel this way and are never written to disk, database or logs
 * (ADR-005 D1.7); `sha256` is what the audit trail stores instead.
 */
final readonly class AiAttachment
{
    public function __construct(
        public string $mimeType,
        public string $bytes,
        public string $sha256,
    ) {}
}
