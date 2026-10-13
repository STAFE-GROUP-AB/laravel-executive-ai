<?php

declare(strict_types=1);

namespace App\Approvals\Policies;

use App\AI\Tools\PolicyDecision;
use App\Models\AI\ToolRun;
use App\Models\Approvals\ActionApproval;
use App\Models\Approvals\ActionProposal;

/**
 * The gate's only read path into the database: the proposal a decision is about,
 * the approval that authorises it, and how much of its budget a run has spent.
 *
 * Keeping these three in one small class is what lets
 * `tests/Security/PolicyIsNotPromptTest.php` state the §4 property as a structural
 * fact rather than as a review habit: `PolicyGate` reads the `RuleBook` and this
 * ledger, and nothing else. No message store, no transcript, no prompt, no tool
 * description.
 *
 * **It is read-only, deliberately.** There is no method here that writes
 * `action_approvals`, and that is the whole of the replay argument. A gate that
 * could record an approval could record a second one for a spent nonce — or, worse,
 * could be the path by which a model-elicited "approval" came into existence
 * (ADR-005 D1.6 forbids exactly that: approvals come only from an authenticated,
 * re-authenticated UI action). Because the gate only reads, the unique indexes
 * `action_approvals_proposal_unique` and `action_approvals_nonce_unique` are the
 * sole authority on whether a second approval exists, and the gate cannot route
 * around them.
 */
final class ApprovalLedger
{
    /**
     * The frozen proposal the action names, or null when it names none or names one
     * that does not exist. A missing row and a **foreign** row are deliberately the
     * same answer — the query is scoped to the run's organization, so a proposal id
     * belonging to another tenant reads as absent and the refusal cannot be used to
     * probe which ids exist (the argument `TenantRowGuard` makes for S2-03's tables).
     */
    public function proposalFor(ProposedAction $action, string $organizationId): ?ActionProposal
    {
        if ($action->proposalId === null) {
            return null;
        }

        return ActionProposal::query()
            ->whereKey($action->proposalId)
            ->where('organization_id', $organizationId)
            ->first();
    }

    /**
     * The one approval a proposal can ever have. `action_approvals` is unique on
     * `action_proposal_id`, so `first()` is not a narrowing — there is at most one
     * row, by construction.
     */
    public function approvalFor(ActionProposal $proposal): ?ActionApproval
    {
        return ActionApproval::query()
            ->where('action_proposal_id', $proposal->getKey())
            ->first();
    }

    /**
     * How many calls of this tool the run has already been **allowed**, read from
     * `tool_runs` rather than from a counter in memory. A retried job, a resumed
     * stream and a second worker all see the same number, which is what makes
     * `ActionBudget::maxCallsPerRun` a bound rather than a hint.
     *
     * Refused calls do not count against the budget: a refusal consumed nothing, and
     * counting them would let an attacker exhaust a budget by proposing nonsense.
     */
    public function allowedCallsInRun(ToolCallContext $context, string $toolName): int
    {
        return ToolRun::query()
            ->where('organization_id', $context->organizationId)
            ->where('trace_id', $context->traceId)
            ->where('tool_name', $toolName)
            ->where('policy_decision', PolicyDecision::Allowed->value)
            ->count();
    }
}
