<?php

declare(strict_types=1);

namespace App\Support\Health;

/**
 * `pending_approvals`: the only field allowed to drive a notification badge
 * (health contract, §1.1). The approvals module rebinds this when
 * `action_proposals` lands; until then there is nothing to approve.
 */
interface PendingApprovals
{
    public function count(): int;
}
