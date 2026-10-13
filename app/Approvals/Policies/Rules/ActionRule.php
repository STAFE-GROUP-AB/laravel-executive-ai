<?php

declare(strict_types=1);

namespace App\Approvals\Policies\Rules;

use App\AI\Tools\ToolKind;
use App\Approvals\ProposalKind;
use App\Support\Data\SensitivityClass;

/**
 * One action the `PolicyGate` knows how to decide about.
 *
 * **A rule declares; it does not decide.** Every method here returns data — a tool
 * name, an argument schema, a list of sensitivity classes, a destination shape, a
 * budget, two booleans — and `PolicyGate` runs the seven §4 checks over that data.
 * Nothing in a rule reads a tool description, a model's output or any prompt text,
 * and `tests/Security/PolicyIsNotPromptTest.php` fails if one starts to (ADR-005
 * D4.8: policy is implemented in code, never in prompt text).
 *
 * That split is what makes the mechanism extensible without being weakened.
 * E3-5's MCP tools and E3-6's automation add a **rule** — a class in this directory
 * and a line in `RuleBook` — not a new code path, and not an `if` in a caller. A
 * tool with no rule is refused by `PolicyReason::NoRuleMatched`, so the cost of
 * forgetting is a refusal rather than an unchecked action.
 *
 * `requiresApproval()` is the one that replaces S2-13's blanket "every bridged tool
 * always requires approval". The blanket was deliberately stricter than ADR-004 D2
 * sketched; this is the same strictness expressed as a decision a human wrote down
 * per action, with the fail-closed default unchanged for anything not written down.
 */
interface ActionRule
{
    /**
     * The rule's own name, as `tool_runs.policy_reason` and the audit row record it.
     * It names the rule, not the tool: "which rule refused this" is the question an
     * incident review asks.
     */
    public function name(): string;

    /**
     * §4 input 1. The tool names this rule claims, exactly as the model sees them
     * and as `tool_runs.tool_name` records them. More than one only where the same
     * declaration is literally the same (the read-only queries).
     *
     * @return list<string>
     */
    public function toolNames(): array;

    /**
     * The `action_proposals` kind this action freezes into, or null for an action
     * that is not approval-driven and therefore freezes no proposal.
     */
    public function kind(): ?ProposalKind;

    /**
     * Where the tool behind the call came from, as `tool_runs.tool_kind` records it.
     * It is declared rather than inferred because the column is not nullable and a
     * decision must always be recordable: the gate cannot fall back to "unknown".
     */
    public function toolKind(): ToolKind;

    /** §4 input 2. */
    public function schema(): ArgumentSchema;

    /**
     * §4 input 4. The sensitivity classes this action may touch. An action that
     * declares a class outside this list is refused, and an action that declares
     * none at all is refused as well — there is no "unclassified" default.
     *
     * @return list<SensitivityClass>
     */
    public function dataClasses(): array;

    /** §4 input 5. */
    public function destinations(): DestinationRule;

    /** §4 input 6. Null is a refusal, not an absence of limits. */
    public function budget(): ?ActionBudget;

    /**
     * §4 input 7. True for every mutating action (§11: "Mail send, CRM deletion,
     * GitHub write och externa formulär alltid approval-driven", plus the ADR-005
     * UI action), which means the gate requires a frozen `action_proposals` row and
     * a matching `action_approvals` row before it will allow anything.
     */
    public function requiresApproval(): bool;

    /**
     * §4 input 3. True when the action goes through a connected account, so the
     * account on the action must be present and must equal the one the run is
     * scoped to (§5.6 criterion 4).
     */
    public function requiresAccountScope(): bool;
}
