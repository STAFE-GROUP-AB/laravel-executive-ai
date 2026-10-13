<?php

declare(strict_types=1);

namespace App\Approvals\Policies;

/**
 * §4 input 3 — org/account/scope. The action names an organization other than the
 * one the run is scoped to, or the rule needs a connected account and the action
 * names none or names a different one.
 *
 * §5.6 criterion 4 is the second half: account A can never read or send through
 * account B. The organization and the account both come from `ToolCallContext`,
 * which nothing the model emits can reach — so this refusal is what happens when a
 * proposal tries to widen its own scope.
 */
final class ScopeViolationException extends PolicyRefusal
{
    protected const string EXPLANATION = 'the action names a scope outside the run it was proposed in';
}
