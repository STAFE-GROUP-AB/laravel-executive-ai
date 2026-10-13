<?php

declare(strict_types=1);

namespace App\Approvals\Policies;

use App\AI\Tools\ToolKind;
use App\Approvals\Policies\Rules\ActionRule;
use App\Approvals\Policies\Rules\RuleBook;
use App\Models\Approvals\ActionProposal;
use Carbon\CarbonImmutable;

/**
 * The server-side gate blueprint §4 rests on: **the LLM generates proposals, never
 * its own permissions**, and nothing a model proposed executes until this class has
 * validated tool name, input schema, org/account/scope, data class, destination,
 * budget and approval.
 *
 * ## How a rule is matched to an action
 *
 * By tool name, in `RuleBook`, and by nothing else. Not by a chain of `if`s in the
 * callers, not by the tool's description, not by what the model said it wanted —
 * one lookup in one table. A rule then *declares* the other six inputs as data
 * (`Rules/ActionRule`), and the seven checks below run over those declarations. The
 * consequence is the one E3-5 and E3-6 need: a new action is a **rule**, a class in
 * `Rules/` and a line in `RuleBook`, not a new code path through the gate.
 *
 * ## What happens when none matches
 *
 * The call is refused with `PolicyReason::NoRuleMatched` and
 * `NoRuleMatchedException`, the rule name on the record is `none`, and the refusal
 * is written to `tool_runs.policy_decision` and `audit_events` like any other. There
 * is no default-allow anywhere in `evaluate()`: every branch either returns a
 * refusal or falls through to the next check, and the only `allow` is at the end,
 * after all seven have passed.
 *
 * ## How the frozen-payload binding is enforced
 *
 * Three times, on purpose.
 *
 *  1. **Before the checks.** `ProposedAction::hashMatchesFrozen()` compares the hash
 *     that was frozen against the hash of the content now. A payload edited after
 *     the freeze is `PolicyReason::PayloadNotFrozen` before any other input is even
 *     looked at.
 *  2. **Against the row.** When the action names an `action_proposals` row, the
 *     gate compares `proposal_hash` to the content as well, and calls
 *     `ActionProposal::hashMatchesPayload()` — so a raw `UPDATE` around the model is
 *     caught even though the action's own two hashes agree with each other.
 *  3. **At execution.** The verdict carries the hash it was issued for
 *     (`PolicyVerdict::binds()`), and `assertStillBound()` is what an executor calls
 *     immediately before it acts. A payload changed between the allow and the act
 *     invalidates the allow instead of riding on it.
 *
 * ## What it is not
 *
 * It is not the row policy. S2-06's `ToolAuthorizer` answers "may this principal
 * touch this row" with the dispatching user's memberships; this answers "may this
 * action be performed at all, and does it need a human". Both must pass, and
 * neither is a substitute for the other
 * ([authorization.md §9](../../docs/architecture/authorization.md)).
 *
 * It is not the approvals engine either. The gate **reads** `action_approvals`; it
 * never writes one and never consumes a nonce (`ApprovalLedger` has no write
 * method). Creating and spending approvals is S2-08, from a re-authenticated UI
 * action, and the unique indexes on `action_proposal_id` and `nonce` are what make a
 * replay unrecordable rather than merely detectable.
 */
final class PolicyGate
{
    public function __construct(
        private readonly RuleBook $rules,
        private readonly ApprovalLedger $ledger,
        private readonly PolicyJournal $journal,
    ) {}

    /**
     * Decide and record, without throwing. This is what a tool bridge calls: a
     * denial has to come back as a tool result the model can read, with nothing
     * executed (ADR-004 D2, contract matrix row 3), and an exception at that point
     * would be an error rather than an answer.
     */
    public function decide(ProposedAction $action, ToolCallContext $context): PolicyVerdict
    {
        $rule = $this->rules->for($action->toolName);

        if ($rule === null) {
            return $this->journal->record(
                $action,
                $context,
                PolicyVerdict::refuse(RuleBook::NO_RULE, PolicyReason::NoRuleMatched, $action),
                ToolKind::Builtin,
            );
        }

        $problems = $rule->schema()->problems($action->payload);

        return $this->journal->record(
            $action,
            $context,
            $this->evaluate($rule, $action, $context, $problems),
            $rule->toolKind(),
            $problems,
        );
    }

    /**
     * Decide, record, and raise a `PolicyDeniedException` subclass on a refusal.
     *
     * This is the server-side path — the approvals executor, a job, a Livewire
     * action — where there is no model to hand a result to and the only safe
     * continuation is not to continue. The subclass is specific so a caller that
     * cares about one failure (S2-08's screen cares about
     * `ApprovalRequiredException`) can catch it, while the one catch at the agent
     * loop still sees a `PolicyDeniedException`.
     *
     * @throws PolicyRefusal
     */
    public function authorize(ProposedAction $action, ToolCallContext $context): PolicyVerdict
    {
        $verdict = $this->decide($action, $context);

        if (! $verdict->permitsExecution()) {
            throw $this->refusalFor($verdict);
        }

        return $verdict;
    }

    /**
     * The last check before an executor acts: is this verdict still about this
     * content. Separate from `authorize()` because the executor re-reads the row it
     * is about to touch, and the content it re-read is what must be bound — not the
     * content that was in memory when the decision was made.
     *
     * @throws PayloadNotFrozenException
     */
    public function assertStillBound(PolicyVerdict $verdict, ProposedAction $action): void
    {
        if ($verdict->binds($action) && $verdict->permitsExecution()) {
            return;
        }

        throw new PayloadNotFrozenException(
            $verdict->rule,
            $action->toolName,
            PolicyReason::PayloadNotFrozen,
            $verdict->proposalId,
        );
    }

    /** The exception class for a refusal — one per check, mapped from the reason. */
    public function refusalFor(PolicyVerdict $verdict): PolicyRefusal
    {
        $arguments = [$verdict->rule, $verdict->toolName, $verdict->reason, $verdict->proposalId];

        return match ($verdict->reason) {
            PolicyReason::NoRuleMatched => new NoRuleMatchedException(...$arguments),
            PolicyReason::PayloadNotFrozen => new PayloadNotFrozenException(...$arguments),
            PolicyReason::InvalidToolInput => new InvalidToolInputException(...$arguments),
            PolicyReason::ScopeViolation,
            PolicyReason::AccountScopeMissing => new ScopeViolationException(...$arguments),
            PolicyReason::DataClassMissing,
            PolicyReason::DataClassNotAllowed => new DataClassViolationException(...$arguments),
            PolicyReason::DestinationNotAllowed => new DestinationNotAllowedException(...$arguments),
            PolicyReason::BudgetMissing,
            PolicyReason::BudgetExceeded => new BudgetViolationException(...$arguments),
            PolicyReason::ApprovalMissing,
            PolicyReason::ApprovalExpired,
            PolicyReason::ApprovalConsumed,
            PolicyReason::ProposalExpired,
            PolicyReason::ProposalNotPending => new ApprovalRequiredException(...$arguments),
            // An allow has no refusal. Reaching here would mean `permitsExecution()`
            // and this map disagree, which is a defect rather than a decision about
            // the caller — so it is loud, and it still denies.
            PolicyReason::Allowed => new NoRuleMatchedException(...$arguments),
        };
    }

    /** Whether a tool is approval-driven, from its rule — or, with no rule, closed. */
    public function requiresApproval(string $toolName): bool
    {
        return $this->rules->for($toolName)?->requiresApproval() ?? true;
    }

    public function rules(): RuleBook
    {
        return $this->rules;
    }

    /**
     * The seven §4 inputs, in order, with the frozen-payload binding ahead of them.
     * Every branch is a refusal; the single allow is the last statement.
     *
     * @param  list<string>  $schemaProblems
     */
    private function evaluate(ActionRule $rule, ProposedAction $action, ToolCallContext $context, array $schemaProblems): PolicyVerdict
    {
        $name = $rule->name();

        // 0. The decision is about a frozen payload, not about a request.
        if (! $action->hashMatchesFrozen()) {
            return PolicyVerdict::refuse($name, PolicyReason::PayloadNotFrozen, $action);
        }

        // 2. Input schema (1, the tool name, was the lookup that found this rule).
        if ($schemaProblems !== []) {
            return PolicyVerdict::refuse($name, PolicyReason::InvalidToolInput, $action);
        }

        // 3. Org / account / scope.
        if ($action->organizationId !== $context->organizationId) {
            return PolicyVerdict::refuse($name, PolicyReason::ScopeViolation, $action);
        }

        if ($rule->requiresAccountScope() && ($action->accountId === null || $action->accountId !== $context->accountId)) {
            return PolicyVerdict::refuse($name, PolicyReason::AccountScopeMissing, $action);
        }

        // 4. Data class.
        if ($action->dataClass === null) {
            return PolicyVerdict::refuse($name, PolicyReason::DataClassMissing, $action);
        }

        if (! in_array($action->dataClass, $rule->dataClasses(), true)) {
            return PolicyVerdict::refuse($name, PolicyReason::DataClassNotAllowed, $action);
        }

        // 5. Destination.
        $destinationRefusal = $rule->destinations()->refusal($action);

        if ($destinationRefusal !== null) {
            return PolicyVerdict::refuse($name, $destinationRefusal, $action);
        }

        // 6. Budget. A rule with none is refused, not unbounded.
        $budget = $rule->budget();

        if ($budget === null) {
            return PolicyVerdict::refuse($name, PolicyReason::BudgetMissing, $action);
        }

        $budgetRefusal = $budget->refusal($action, $this->ledger->allowedCallsInRun($context, $action->toolName));

        if ($budgetRefusal !== null) {
            return PolicyVerdict::refuse($name, $budgetRefusal, $action);
        }

        // 7. Approval.
        if (! $rule->requiresApproval()) {
            return PolicyVerdict::allowRead($name, $action);
        }

        return $this->approval($name, $action, $context);
    }

    /**
     * §4 input 7. Every branch names what was missing, because "refused" without
     * "which approval" is what S0-21 cannot assert on.
     */
    private function approval(string $rule, ProposedAction $action, ToolCallContext $context): PolicyVerdict
    {
        $now = CarbonImmutable::now('UTC');
        $proposal = $this->ledger->proposalFor($action, $context->organizationId);

        if ($proposal === null) {
            return PolicyVerdict::refuse($rule, PolicyReason::ApprovalMissing, $action);
        }

        $proposalId = (string) $proposal->getKey();

        // The binding, again — this time against the row rather than against the
        // action's own copy, so a payload edited around the model is caught even
        // when the action was built from that edited payload.
        if (! hash_equals($proposal->proposal_hash, $action->currentHash()) || ! $proposal->hashMatchesPayload()) {
            return PolicyVerdict::refuse($rule, PolicyReason::PayloadNotFrozen, $action, $proposalId);
        }

        if ($proposal->isExpired($now)) {
            return PolicyVerdict::refuse($rule, PolicyReason::ProposalExpired, $action, $proposalId);
        }

        if (! $this->isLive($proposal)) {
            return PolicyVerdict::refuse($rule, PolicyReason::ProposalNotPending, $action, $proposalId);
        }

        $approval = $this->ledger->approvalFor($proposal);

        if ($approval === null) {
            return PolicyVerdict::refuse($rule, PolicyReason::ApprovalMissing, $action, $proposalId);
        }

        if ($approval->consumed_at !== null) {
            return PolicyVerdict::refuse($rule, PolicyReason::ApprovalConsumed, $action, $proposalId);
        }

        if ($approval->isExpired($now)) {
            return PolicyVerdict::refuse($rule, PolicyReason::ApprovalExpired, $action, $proposalId);
        }

        // `ActionApproval::authorises()` is the model's own statement of the same
        // rule — proposal identity, unspent nonce, hash the approver saw. It is
        // asked last rather than first so the reason above is the specific one; it
        // being asked at all is what keeps the gate and the row in agreement.
        if (! $approval->authorises($proposal, $now)) {
            return PolicyVerdict::refuse($rule, PolicyReason::ApprovalMissing, $action, $proposalId);
        }

        return PolicyVerdict::allowApproved($rule, $action, $proposal, $approval);
    }

    /**
     * A proposal an approval may still authorise: pending (the approval row is what
     * makes it approved) or already marked approved. Everything else — rejected,
     * expired, executed, superseded — is terminal, and `ProposalStatus::isTerminal()`
     * is the one definition of that.
     */
    private function isLive(ActionProposal $proposal): bool
    {
        return ! $proposal->status->isTerminal();
    }
}
