<?php

declare(strict_types=1);

namespace App\Support\Health;

/**
 * `pending_approvals`: the only field allowed to drive a notification badge
 * (health contract, §1.1), and the read-only list the menu bar shows
 * (IPC contract §6.3). The approvals module rebinds this when
 * `action_proposals` lands; until then there is nothing to approve.
 * There is deliberately no mutating method here: approvals are decided in
 * the PWA, never over IPC (§11.2).
 */
interface PendingApprovals
{
    public function count(): int;

    /** @param int $limit 1–50 */
    public function page(int $limit, ?string $cursor = null): PendingApprovalsPage;
}
