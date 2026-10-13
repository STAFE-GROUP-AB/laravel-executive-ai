<?php

declare(strict_types=1);

namespace App\Approvals\Policies\Rules;

use App\Approvals\Policies\PolicyReason;
use App\Approvals\Policies\ProposedAction;

/**
 * §4 input 6: the bound on one action, declared as data.
 *
 * Three numbers, and all three are needed for the check to mean anything:
 *
 *  - `maxCallsPerRun` — how many times this tool may be decided *allowable* within
 *    one `ai_runs` trace. A compromised model that proposes the same send a hundred
 *    times is refused on the second, so the budget is a rate limit the model cannot
 *    argue with. The count comes from `tool_runs`, not from a counter in memory, so
 *    it survives a retried job.
 *  - `maxCanonicalBytes` — the size of the RFC 8785 canonical payload. A payload
 *    that grew past this is refused rather than truncated: a truncated action is an
 *    action nobody approved.
 *  - `maxDestinations` — how many places one action may reach. One mail to six
 *    hundred recipients is a different action from one mail to six.
 *
 * There is no default: `ActionRule::budget()` may return null, and the gate reads
 * that as `PolicyReason::BudgetMissing`. A rule that forgot its budget is refused,
 * which is the whole §4 "missing budget is a refusal, not a default-allow".
 */
final readonly class ActionBudget
{
    public function __construct(
        public int $maxCallsPerRun,
        public int $maxCanonicalBytes,
        public int $maxDestinations,
    ) {}

    /**
     * @param  int  $allowedCallsInRun  how many calls of this tool the trace already has
     */
    public function refusal(ProposedAction $action, int $allowedCallsInRun): ?PolicyReason
    {
        if ($allowedCallsInRun >= $this->maxCallsPerRun) {
            return PolicyReason::BudgetExceeded;
        }

        if ($action->canonicalBytes() > $this->maxCanonicalBytes) {
            return PolicyReason::BudgetExceeded;
        }

        if (count($action->destinations) > $this->maxDestinations) {
            return PolicyReason::BudgetExceeded;
        }

        return null;
    }
}
