<?php

declare(strict_types=1);

namespace App\Approvals;

use RuntimeException;

/**
 * An attempt to change the frozen content of an `action_proposals` row after
 * `frozen_at` was set (§11 "frozen immutable proposal"). The hash in
 * `proposal_hash` makes such a change *detectable*; this exception makes it not
 * happen, so a reviewer never approves one payload and a connector then sends
 * another.
 *
 * Only `status` and the timestamps that record what happened to the proposal may
 * move after a freeze; `App\Models\Approvals\ActionProposal::MUTABLE_AFTER_FREEZE`
 * is the list, and the model checks it on every save.
 */
final class FrozenProposalException extends RuntimeException
{
    /** @param list<string> $columns the frozen columns the save tried to change */
    public function __construct(
        public readonly string $proposalId,
        public readonly array $columns,
    ) {
        parent::__construct(sprintf(
            'Proposal %s is frozen; %s cannot change after freeze (§11).',
            $proposalId,
            implode(', ', $columns),
        ));
    }
}
