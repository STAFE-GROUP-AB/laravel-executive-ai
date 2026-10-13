<?php

declare(strict_types=1);

namespace App\Approvals\Policies;

/**
 * Why the `PolicyGate` decided the way it did — a frozen vocabulary, one value per
 * failure mode of the seven §4 checks plus the frozen-payload binding.
 *
 * It is an enum rather than a free string for one reason: the reason is written to
 * `tool_runs.policy_reason` and to `audit_events.context`, and both must carry
 * **references only** (ADR-003 D7 row 8). A string assembled at the point of
 * refusal would eventually quote an argument the model supplied, which is customer
 * content in a forensic record. A value from this list cannot.
 *
 * It is also the vocabulary the UI reads: `messageKey()` is the translation key the
 * owner is shown, and tests assert the key rather than the translated string
 * (`tests/Security/README.md`).
 */
enum PolicyReason: string
{
    /** The only value that permits execution. */
    case Allowed = 'allowed';

    /** §4 input 1 — tool name: no rule in `Rules/` claims this tool. Fail closed. */
    case NoRuleMatched = 'no_rule_matched';

    /** The payload is not the one that was frozen: `proposal_hash` no longer matches it. */
    case PayloadNotFrozen = 'payload_not_frozen';

    /** §4 input 2 — input schema: a missing, mistyped or unexpected argument. */
    case InvalidToolInput = 'invalid_tool_input';

    /** §4 input 3 — org/account/scope: the action names an organization the run is not in. */
    case ScopeViolation = 'scope_violation';

    /** §4 input 3 — the rule needs a connected account and the action names none, or names another. */
    case AccountScopeMissing = 'account_scope_missing';

    /** §4 input 4 — data class: the action declares none. */
    case DataClassMissing = 'data_class_missing';

    /** §4 input 4 — data class: the rule does not permit the class the action carries. */
    case DataClassNotAllowed = 'data_class_not_allowed';

    /** §4 input 5 — destination: not of the declared kind, or not one the frozen payload named. */
    case DestinationNotAllowed = 'destination_not_allowed';

    /** §4 input 6 — budget: the rule declares none, so there is no bound to check against. */
    case BudgetMissing = 'budget_missing';

    /** §4 input 6 — budget: calls per run, canonical payload size or destination count. */
    case BudgetExceeded = 'budget_exceeded';

    /** §4 input 7 — approval: no `action_approvals` row authorises this proposal. */
    case ApprovalMissing = 'approval_missing';

    /** §4 input 7 — approval: the approval's own expiry has passed. */
    case ApprovalExpired = 'approval_expired';

    /** §4 input 7 — approval: the nonce has already been spent. */
    case ApprovalConsumed = 'approval_consumed';

    /** §4 input 7 — approval: the proposal expired before it could be executed. */
    case ProposalExpired = 'proposal_expired';

    /** §4 input 7 — approval: the proposal is rejected, superseded or already executed. */
    case ProposalNotPending = 'proposal_not_pending';

    public function permitsExecution(): bool
    {
        return $this === self::Allowed;
    }

    /**
     * The translation key the owner is shown. A refusal the owner cannot see is
     * indistinguishable from the attack having worked (§4.2), so every refusal has
     * one.
     */
    public function messageKey(): string
    {
        return 'policy.'.$this->value;
    }
}
