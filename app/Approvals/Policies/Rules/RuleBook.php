<?php

declare(strict_types=1);

namespace App\Approvals\Policies\Rules;

use InvalidArgumentException;

/**
 * Every action the `PolicyGate` knows about, indexed by the tool name the model
 * uses. §4 input 1 is a lookup in this table, and a miss is
 * `PolicyReason::NoRuleMatched` — a refusal, never a default-allow.
 *
 * The default set is small on purpose, and what it leaves out is the point:
 *
 * | tool | rule | approval |
 * |---|---|---|
 * | `email.send` | `OutgoingEmailRule` | required |
 * | `crm.delete` | `CrmDeletionRule` | required |
 * | `automation.execute` | `UiActionRule` | required |
 * | `crm.search`, `tasks.list`, `brief.read` | `ReadOnlyQueryRule` | not required |
 *
 * `settings.write` and `approvals.grant` — the two calls the §16.7 privilege-
 * escalation case makes — have **no rule and will not get one**: there is no tool
 * that widens the agent's own authority (ADR-005 D1.6, "session grants and
 * approvals are never issued from a prompt or a tool result"). They are refused
 * because nothing in this table claims them, which is a stronger statement than a
 * rule that refuses them would be: a rule can be edited, an absence cannot be
 * edited into permission.
 *
 * `ProposalKind::GithubWrite` and `ProposalKind::ExternalForm` are in the schema
 * and have no rule either, for a different reason: their tool names are not fixed
 * yet. Until a rule names them they are refused, which is the correct state for an
 * action nobody has specified.
 */
final class RuleBook
{
    /** The rule name recorded when no rule claimed the tool. */
    public const string NO_RULE = 'none';

    /** @var array<string, ActionRule> */
    private array $byToolName = [];

    /** @var list<ActionRule> */
    private array $rules;

    /**
     * The default set. A no-argument constructor so the container can autowire
     * `PolicyGate` with no provider registration — one less place a rule could be
     * dropped from without anything failing.
     */
    public function __construct()
    {
        $this->index(
            new OutgoingEmailRule,
            new CrmDeletionRule,
            new UiActionRule,
            new ReadOnlyQueryRule,
        );
    }

    /** An explicit set, for tests that need a rule the product does not ship. */
    public static function of(ActionRule ...$rules): self
    {
        $book = new self;
        $book->index(...$rules);

        return $book;
    }

    public function for(string $toolName): ?ActionRule
    {
        return $this->byToolName[$toolName] ?? null;
    }

    /** @return list<string> every tool name the gate can decide about, sorted */
    public function toolNames(): array
    {
        $names = array_keys($this->byToolName);
        sort($names, SORT_STRING);

        return $names;
    }

    /** @return list<ActionRule> */
    public function rules(): array
    {
        return $this->rules;
    }

    private function index(ActionRule ...$rules): void
    {
        $this->rules = array_values($rules);
        $this->byToolName = [];

        foreach ($this->rules as $rule) {
            if ($rule->toolNames() === []) {
                throw new InvalidArgumentException("Rule {$rule->name()} claims no tool name, so nothing can ever match it.");
            }

            foreach ($rule->toolNames() as $toolName) {
                if (isset($this->byToolName[$toolName])) {
                    // Two rules for one tool means the decision depends on
                    // registration order, which is exactly the kind of implicit
                    // precedence a security decision may not have.
                    throw new InvalidArgumentException("Tool {$toolName} is claimed by two rules: {$this->byToolName[$toolName]->name()} and {$rule->name()}.");
                }

                $this->byToolName[$toolName] = $rule;
            }
        }
    }
}
