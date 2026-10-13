<?php

declare(strict_types=1);

namespace App\Approvals\Policies;

use App\Models\Approvals\ActionProposal;
use App\Support\Data\SensitivityClass;
use App\Support\Json\Jcs;

/**
 * What the model proposed, frozen: the tool it named, the arguments it supplied,
 * the destinations those arguments resolve to, the data class the action touches,
 * and the hash of the payload as it was at freeze time.
 *
 * **A decision is about a frozen payload, not about a request.** That sentence is
 * the reason this class exists rather than the gate taking an array. Two hashes are
 * kept apart:
 *
 *  - `frozenHash` is what was hashed when the proposal was frozen — copied off the
 *    `action_proposals` row when there is one (`fromProposal()`), or computed once
 *    at `freeze()` when the action has not been persisted yet;
 *  - `currentHash()` is computed from `payload` every time it is asked.
 *
 * `hashMatchesFrozen()` is the two compared, and `PolicyGate` refuses when they
 * differ (`PolicyReason::PayloadNotFrozen`). `PolicyVerdict::binds()` then compares
 * the verdict's own bound hash against `currentHash()` again at the moment of
 * execution, so a payload edited *after* an allow invalidates the allow rather than
 * riding on it. The rule is the same one `ActionProposal::hashMatchesPayload()`
 * states for the row, over the same RFC 8785 canonical form (`Jcs`), so a hash
 * computed here and one computed from the database are the same bytes.
 *
 * Nothing here is model text: no tool description, no instructions, no model output
 * (ADR-005 D4.8, `tests/Security/PolicyIsNotPromptTest.php`). `payload` is
 * arguments, which are data the gate validates against a declared schema.
 */
final readonly class ProposedAction
{
    /**
     * @param  array<string, mixed>  $payload  the arguments the model proposed, frozen
     * @param  list<string>  $destinations  where the action would reach outside this machine
     */
    private function __construct(
        public string $toolName,
        public string $organizationId,
        public array $payload,
        public string $frozenHash,
        public array $destinations,
        public ?SensitivityClass $dataClass,
        public ?string $accountId,
        public ?string $proposalId,
    ) {}

    /**
     * Freeze a proposal that has no `action_proposals` row yet: the hash is computed
     * from the payload exactly once, here, and every later check is against it.
     *
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $destinations
     */
    public static function freeze(
        string $toolName,
        string $organizationId,
        array $payload,
        array $destinations = [],
        ?SensitivityClass $dataClass = null,
        ?string $accountId = null,
    ): self {
        return new self(
            toolName: $toolName,
            organizationId: $organizationId,
            payload: $payload,
            frozenHash: ActionProposal::hashPayload($payload),
            destinations: $destinations,
            dataClass: $dataClass,
            accountId: $accountId,
            proposalId: null,
        );
    }

    /**
     * Bind the decision to a persisted proposal. The hash is taken from the **row**,
     * never recomputed from the payload: if somebody edited `payload` around the
     * model (a raw `UPDATE`), recomputing would silently bless the edit, while
     * copying `proposal_hash` makes the mismatch a refusal.
     *
     * `$dataClass` has no fallback to the row's `sensitivity_class` on purpose. The
     * class an action operates at is the caller's declaration, and a caller that
     * declares none gets `PolicyReason::DataClassMissing` — a refusal it will see —
     * rather than whichever default the column happened to carry.
     *
     * @param  list<string>  $destinations
     */
    public static function fromProposal(
        ActionProposal $proposal,
        string $toolName,
        array $destinations = [],
        ?SensitivityClass $dataClass = null,
        ?string $accountId = null,
    ): self {
        return new self(
            toolName: $toolName,
            organizationId: (string) $proposal->organization_id,
            payload: $proposal->payload,
            frozenHash: $proposal->proposal_hash,
            destinations: $destinations,
            dataClass: $dataClass,
            accountId: $accountId,
            proposalId: (string) $proposal->getKey(),
        );
    }

    /** The hash of the payload as it is right now. */
    public function currentHash(): string
    {
        return ActionProposal::hashPayload($this->payload);
    }

    public function hashMatchesFrozen(): bool
    {
        return hash_equals($this->frozenHash, $this->currentHash());
    }

    /**
     * The same action with different content and the **same** frozen hash — a
     * payload changed after the freeze. The executor builds this when it re-reads
     * the row it is about to act on, and the tests use it to prove a changed payload
     * invalidates a decision instead of riding on it.
     *
     * @param  array<string, mixed>  $payload
     */
    public function withPayload(array $payload): self
    {
        return new self(
            toolName: $this->toolName,
            organizationId: $this->organizationId,
            payload: $payload,
            frozenHash: $this->frozenHash,
            destinations: $this->destinations,
            dataClass: $this->dataClass,
            accountId: $this->accountId,
            proposalId: $this->proposalId,
        );
    }

    /** The size the budget rule bounds: canonical bytes, not PHP array depth. */
    public function canonicalBytes(): int
    {
        return strlen(Jcs::canonicalize($this->payload));
    }

    /**
     * The argument names the payload carries, sorted — a reference set for the
     * schema check and for `tool_runs`, with no values in it.
     *
     * @return list<string>
     */
    public function argumentNames(): array
    {
        $names = array_map(strval(...), array_keys($this->payload));
        sort($names, SORT_STRING);

        return $names;
    }
}
