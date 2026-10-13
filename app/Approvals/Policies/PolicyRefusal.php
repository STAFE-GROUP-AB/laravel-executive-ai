<?php

declare(strict_types=1);

namespace App\Approvals\Policies;

/**
 * The shape every `PolicyGate` refusal has: which rule refused, which tool was
 * named, which `PolicyReason` from the frozen vocabulary, and the proposal the
 * decision was about when there was one.
 *
 * It sits between `PolicyDeniedException` — the S0-16 base that the one catch at
 * the agent loop looks for (`docs/architecture/module-contracts.md` §1) — and the
 * eight concrete refusals, one per check. The concrete class is what a caller
 * catches when it wants to react to a specific failure (S2-08's approvals screen
 * cares about `ApprovalRequiredException` and nothing else); the base is what the
 * agent loop catches when it only needs to turn any denial into a tool result.
 *
 * **The message carries references only.** It is built here from a tool name, a
 * rule name and an enum value, and the concrete subclasses add a fixed sentence —
 * so there is no branch on which an argument, an address, a mail body or a secret
 * can reach the message, the log or the audit row. That is not a convention the
 * subclasses have to remember: they cannot pass anything else in.
 */
abstract class PolicyRefusal extends PolicyDeniedException
{
    public function __construct(
        public readonly string $rule,
        public readonly string $toolName,
        public readonly PolicyReason $reason,
        public readonly ?string $proposalId = null,
    ) {
        parent::__construct(
            sprintf(
                'Tool [%s] was refused by rule [%s]: %s (%s).',
                $this->toolName,
                $this->rule,
                static::EXPLANATION,
                $this->reason->value,
            ),
            $this->rule,
        );
    }

    /** A fixed sentence, written once per check; never assembled from input. */
    protected const string EXPLANATION = 'the policy gate refused the action';

    /** The translation key the owner is shown, so a refusal is never silent. */
    public function messageKey(): string
    {
        return $this->reason->messageKey();
    }
}
