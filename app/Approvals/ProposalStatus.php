<?php

declare(strict_types=1);

namespace App\Approvals;

/**
 * Lifecycle of an `action_proposals` row. The frozen content never changes once
 * `frozen_at` is set (§11 "frozen immutable proposal") — only this column and the
 * timestamps that record what happened to the proposal do, which
 * `App\Models\Approvals\ActionProposal` enforces on every save.
 *
 * Forward-only: `Pending` is the only status an approval may be recorded against,
 * and `Executed` is terminal.
 */
enum ProposalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Executed = 'executed';
    case Superseded = 'superseded';

    public function isApprovable(): bool
    {
        return $this === self::Pending;
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Rejected, self::Expired, self::Executed, self::Superseded => true,
            self::Pending, self::Approved => false,
        };
    }
}
