<?php

declare(strict_types=1);

namespace App\Approvals\Policies;

use App\AI\Tools\PolicyDecision;
use App\Models\Approvals\ActionApproval;
use App\Models\Approvals\ActionProposal;
use Carbon\CarbonImmutable;

/**
 * One `PolicyGate` decision, bound to the payload it was made about.
 *
 * The binding is the point. A verdict is not "the gate said yes"; it is "the gate
 * said yes **about these bytes**", and `binds()` is what an executor calls
 * immediately before it acts. A payload edited between the decision and the
 * execution fails `binds()`, so the allow cannot be carried over to content nobody
 * approved — the same argument ADR-005 D1.6 makes for recomputing `proposal_hash`
 * in the provider, applied to the gate's own output.
 *
 * On an allow of a mutating action the verdict also carries the §11 approval
 * evidence: `proposalId`, `approvalId`, `approverUserId`, `nonce`, `approvedAt`,
 * `expiresAt`. That is exactly the field set
 * `App\Browser\ComputerUse\AuthorizedUiAction` and
 * `App\Integrations\Mail\Dto\ApprovedOutgoingEmail` require, and those two classes
 * may only be constructed by the policy layer — so the gate is the one place the
 * evidence comes from, rather than each connector assembling its own.
 */
final readonly class PolicyVerdict
{
    public function __construct(
        public PolicyDecision $decision,
        public string $rule,
        public PolicyReason $reason,
        public string $toolName,
        public string $boundHash,
        public ?string $proposalId = null,
        public ?string $approvalId = null,
        public ?string $approverUserId = null,
        public ?string $nonce = null,
        public ?CarbonImmutable $approvedAt = null,
        public ?CarbonImmutable $expiresAt = null,
        public ?string $toolRunId = null,
    ) {}

    /**
     * An allow with no approval behind it — a read-only rule. `requiresApproval()`
     * is false for exactly these, and the verdict carries no approval evidence
     * because there is none to carry.
     */
    public static function allowRead(string $rule, ProposedAction $action): self
    {
        return new self(
            decision: PolicyDecision::Allowed,
            rule: $rule,
            reason: PolicyReason::Allowed,
            toolName: $action->toolName,
            boundHash: $action->currentHash(),
        );
    }

    /**
     * An allow of a mutating action: the proposal and the human approval that
     * authorises exactly this content.
     */
    public static function allowApproved(
        string $rule,
        ProposedAction $action,
        ActionProposal $proposal,
        ActionApproval $approval,
    ): self {
        return new self(
            decision: PolicyDecision::Allowed,
            rule: $rule,
            reason: PolicyReason::Allowed,
            toolName: $action->toolName,
            boundHash: $action->currentHash(),
            proposalId: (string) $proposal->getKey(),
            approvalId: (string) $approval->getKey(),
            approverUserId: (string) $approval->approver_user_id,
            nonce: $approval->nonce,
            approvedAt: $approval->approved_at,
            expiresAt: $approval->expires_at,
        );
    }

    /**
     * `$proposalId` is passed only once the gate has actually **loaded** that row.
     * It is not taken from the action, because the action's id is the model's claim:
     * writing an unverified id into `tool_runs.action_proposal_id` would break its
     * foreign key and turn a recorded refusal into an unrecorded 500 — the one
     * outcome worse than either.
     */
    public static function refuse(string $rule, PolicyReason $reason, ProposedAction $action, ?string $proposalId = null): self
    {
        return new self(
            decision: PolicyDecision::Denied,
            rule: $rule,
            reason: $reason,
            toolName: $action->toolName,
            boundHash: $action->currentHash(),
            proposalId: $proposalId,
        );
    }

    public function permitsExecution(): bool
    {
        return $this->decision->permitsExecution() && $this->reason->permitsExecution();
    }

    /**
     * Whether this verdict is still about that action. Both halves matter: a verdict
     * for one tool may not authorise another, and a verdict for one payload may not
     * authorise edited content.
     */
    public function binds(ProposedAction $action): bool
    {
        return $this->toolName === $action->toolName
            && hash_equals($this->boundHash, $action->currentHash());
    }

    /** The `tool_runs` row the journal wrote for this decision. */
    public function withToolRun(string $toolRunId): self
    {
        return new self(
            decision: $this->decision,
            rule: $this->rule,
            reason: $this->reason,
            toolName: $this->toolName,
            boundHash: $this->boundHash,
            proposalId: $this->proposalId,
            approvalId: $this->approvalId,
            approverUserId: $this->approverUserId,
            nonce: $this->nonce,
            approvedAt: $this->approvedAt,
            expiresAt: $this->expiresAt,
            toolRunId: $toolRunId,
        );
    }

    /**
     * The decision as a tool result the model may read (ADR-004 D2, contract matrix
     * row 3: "denial produces a tool result the model can read, no execution happens
     * on denial").
     *
     * Every field is a reference from a frozen vocabulary — the tool name, the rule
     * name, a `PolicyReason` value — so the result cannot smuggle the payload, a
     * secret or a sentence the model might read as a new instruction back into the
     * transcript (ADR-005 D4.8: results are rendered as data). `executed=none` is
     * literal: a bridged tool definition carries no executor, so neither branch of
     * this method can have run anything.
     */
    public function toToolResult(): string
    {
        return sprintf(
            '[policy_gate] decision=%s tool=%s rule=%s reason=%s executed=none',
            $this->decision->value,
            $this->toolName,
            $this->rule,
            $this->reason->value,
        );
    }
}
