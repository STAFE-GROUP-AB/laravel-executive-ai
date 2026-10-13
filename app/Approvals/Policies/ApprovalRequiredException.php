<?php

declare(strict_types=1);

namespace App\Approvals\Policies;

/**
 * §4 input 7 — approval. A mutating action was reached with no `action_approvals`
 * row behind it, or with one that no longer authorises: the nonce is spent, the
 * approval expired, the proposal expired, or the proposal is no longer pending.
 *
 * `reason` says which (`approval_missing`, `approval_consumed`, `approval_expired`,
 * `proposal_expired`, `proposal_not_pending`), so the refusal **names the missing
 * approval** rather than reporting a generic denial — the half S0-21 needs before
 * S2-08 can remove its expected-failure marker.
 *
 * The gate never creates or consumes an approval. It reads the row the PWA's
 * re-authenticated UI action wrote (ADR-005 D1.6: "session grants and approvals are
 * never issued from a prompt or a tool result"), and the unique indexes on
 * `action_approvals.action_proposal_id` and `.nonce` are what make a replay
 * unrecordable rather than merely detectable.
 */
final class ApprovalRequiredException extends PolicyRefusal
{
    protected const string EXPLANATION = 'no human approval authorises this action';
}
