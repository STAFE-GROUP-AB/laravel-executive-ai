<?php

declare(strict_types=1);

namespace App\Support\Health;

use Carbon\CarbonImmutable;

/**
 * One item of the read-only pending-approvals list (IPC contract §6.3).
 * `summary` is sanitised by the producer: ≤ 500 characters, no secrets, no
 * mail bodies. Approving happens in the PWA only; this DTO carries no state
 * a client could mutate.
 */
final readonly class PendingApproval
{
    public function __construct(
        public string $id,
        public string $kind,
        public string $title,
        public string $summary,
        public CarbonImmutable $createdAt,
        public CarbonImmutable $expiresAt,
        public ?string $briefItemId,
    ) {}
}
