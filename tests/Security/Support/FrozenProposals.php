<?php

declare(strict_types=1);

namespace Tests\Security\Support;

use App\Approvals\ProposalKind;
use App\Approvals\ProposalStatus;
use App\Models\Approvals\ActionApproval;
use App\Models\Approvals\ActionProposal;
use App\Models\Organization;
use App\Models\User;
use App\Support\Data\Provenance;
use App\Support\Data\ProvenanceOrigin;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Real `action_proposals` and `action_approvals` rows for the `PolicyGate` suites
 * (S2-07). Rows, not doubles: the gate's approval check is a set of queries against
 * those two tables and their unique indexes, and a fake repository would be a test
 * of the fake.
 *
 * `freeze()` computes `proposal_hash` with `ActionProposal::hashPayload()` — the one
 * definition of the §11 content hash — so a test that wants a *tampered* payload has
 * to say so explicitly (`tamper()`), which is the point: nothing here can
 * accidentally produce a row whose hash agrees with edited content.
 */
final class FrozenProposals
{
    /** The fixture clock the Security suite runs on (`InjectionCorpus::FIXTURE_NOW`). */
    public const string NOW = '2026-10-09T12:00:00+02:00';

    /**
     * A frozen, pending proposal. `payload` is whatever the caller froze; the hash,
     * the nonce and the idempotency key are derived here so every row is internally
     * consistent to begin with.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function freeze(
        Organization $organization,
        array $payload,
        ProposalKind $kind = ProposalKind::OutgoingEmail,
        ?CarbonImmutable $frozenAt = null,
        ?CarbonImmutable $expiresAt = null,
        ProposalStatus $status = ProposalStatus::Pending,
    ): ActionProposal {
        $frozenAt ??= CarbonImmutable::parse(self::NOW)->utc();

        return ActionProposal::query()->create([
            'organization_id' => $organization->getKey(),
            'kind' => $kind,
            'status' => $status,
            'proposed_by' => 'ai:ollama/llama3.2:3b',
            'payload' => $payload,
            'proposal_hash' => ActionProposal::hashPayload($payload),
            'nonce' => bin2hex(random_bytes(16)),
            'idempotency_key' => (string) Str::ulid(),
            'frozen_at' => $frozenAt,
            'expires_at' => $expiresAt ?? $frozenAt->addMinutes(15),
            'sensitivity_class' => 'internal',
            'provenance' => Provenance::job('ProposeAction', ProvenanceOrigin::AiExtraction),
        ]);
    }

    /**
     * The row only a re-authenticated UI action may write (ADR-005 D1.6). The gate
     * never calls this; the suites do, to build the state the gate reads.
     */
    public static function approve(
        ActionProposal $proposal,
        User $approver,
        ?CarbonImmutable $approvedAt = null,
        ?CarbonImmutable $expiresAt = null,
        ?CarbonImmutable $consumedAt = null,
    ): ActionApproval {
        $approvedAt ??= CarbonImmutable::parse(self::NOW)->utc();

        return ActionApproval::query()->create([
            'organization_id' => $proposal->organization_id,
            'action_proposal_id' => $proposal->getKey(),
            'nonce' => $proposal->nonce,
            'proposal_hash' => $proposal->proposal_hash,
            'approver_user_id' => $approver->getKey(),
            'approved_at' => $approvedAt,
            'expires_at' => $expiresAt ?? $proposal->expires_at,
            'reauth_at' => $approvedAt,
            'consumed_at' => $consumedAt,
            'sensitivity_class' => 'internal',
            'provenance' => Provenance::job('ApproveAction', ProvenanceOrigin::Manual),
        ]);
    }

    /**
     * Edit a frozen payload the way an attacker with database access would: a raw
     * `UPDATE`, so the model's `saving` guard is bypassed and `proposal_hash` is left
     * naming content that is no longer there. This is the state
     * `PolicyReason::PayloadNotFrozen` exists for.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function tamper(ActionProposal $proposal, array $payload): ActionProposal
    {
        ActionProposal::query()
            ->whereKey($proposal->getKey())
            ->toBase()
            ->update(['payload' => json_encode($payload, JSON_THROW_ON_ERROR)]);

        return $proposal->fresh() ?? $proposal;
    }

    /**
     * The canonical `email.send` payload `OutgoingEmailRule` accepts: a connection
     * id, recipients, a subject and a body, and deliberately **no** `from` — the
     * From is a property of the account (§5.6, T-05).
     *
     * @param  list<string>  $to
     * @return array<string, mixed>
     */
    public static function emailPayload(string $connectionId, array $to, string $subject = 'Re: rollout', string $body = 'Noted.'): array
    {
        return [
            'connection_id' => $connectionId,
            'to' => $to,
            'subject' => $subject,
            'body' => $body,
        ];
    }
}
