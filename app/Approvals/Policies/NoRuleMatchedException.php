<?php

declare(strict_types=1);

namespace App\Approvals\Policies;

/**
 * §4 input 1 — tool name. No rule in `Rules/` claims the tool the model named, so
 * nothing describes what a safe call to it would look like, and the gate refuses
 * rather than guessing.
 *
 * This is the fail-closed default made visible: a tool added without a rule is
 * unusable, which is the cheap failure. The expensive one would be an unknown tool
 * that runs because no rule said it must not.
 */
final class NoRuleMatchedException extends PolicyRefusal
{
    protected const string EXPLANATION = 'no rule claims this tool, so no call to it can be validated';
}
