<?php

declare(strict_types=1);

namespace App\Support\Health;

/**
 * A page of pending approvals with the opaque cursor for the next one
 * (IPC contract §6.3).
 */
final readonly class PendingApprovalsPage
{
    /** @param list<PendingApproval> $items */
    public function __construct(
        public array $items,
        public ?string $nextCursor = null,
    ) {}
}
