<?php

declare(strict_types=1);

namespace App\Approvals\Policies;

/**
 * §4 input 6 — budget. The action is outside its rule's bound on calls per run,
 * canonical payload size or destination count; or the rule declares **no** budget,
 * which is the same refusal.
 *
 * A missing budget being a refusal rather than "unlimited" is the §4 requirement
 * spelled out: a rule author who omits the bound gets a refusal they will notice,
 * not an action with no ceiling.
 */
final class BudgetViolationException extends PolicyRefusal
{
    protected const string EXPLANATION = 'the action is outside the budget its rule declares, or the rule declares none';
}
