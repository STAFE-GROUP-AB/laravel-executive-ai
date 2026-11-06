<?php

declare(strict_types=1);

namespace App\CRM\Deletions;

use App\Approvals\ProposalKind;
use App\Approvals\ProposalStatus;
use App\CRM\Eloquent\CrmEntity;
use App\CRM\Eloquent\RetentionStamp;
use App\CRM\WorkspaceScope;
use App\Models\Approvals\ActionApproval;
use App\Models\Approvals\ActionProposal;
use App\Models\CRM\CrmModel;
use App\Models\User;
use App\Security\Audit\AuditLog;
use App\Support\Data\Provenance;
use App\Support\Data\ProvenanceOrigin;
use App\Support\Data\SensitivityClass;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Deleting a CRM record, the way §11 requires: **"CRM deletion always
 * approval-driven"**.
 *
 * So there is no path from a button to a `delete()`. A deletion is two
 * recorded decisions:
 *
 *  1. **Propose.** A frozen `action_proposals` row of kind `crm_deletion`,
 *     carrying what is about to be deleted, its hash, a single-use nonce, an
 *     idempotency key and an expiry. The record itself is untouched; the screen
 *     shows it as awaiting approval. The content is immutable from this moment
 *     — `ActionProposal` refuses to save a change to a frozen column.
 *  2. **Approve.** An `action_approvals` row naming the approver and the hash
 *     they saw, and only then the soft delete, inside one transaction with the
 *     proposal moving to `executed`. `action_approvals_proposal_unique` and
 *     `action_approvals_nonce_unique` make a replayed approval *unrecordable*
 *     rather than merely detectable (the ADR-009 D1.2 argument).
 *
 * Three things this is deliberately **not**:
 *
 * - **Not a hard delete.** `delete()` on a `CrmModel` is a soft delete, and
 *   `forceDelete` is refused to everybody on every table because it destroys
 *   the `retention_policy` / `retain_until` evidence the row carries
 *   (authorization.md §3). Erasure is the retention job's, which answers to
 *   `data_retention_rules` and not to a user.
 * - **Not a replacement for the policy.** The caller authorizes
 *   `Abilities::DELETE` on the row *before* calling in, so the tenant decision
 *   is still `CustomerPolicy`'s and still leaves its own audit row. This class
 *   adds the §11 approval on top; both must pass.
 * - **Not the S2-08 engine.** S2-08 (#92) owns `app/Approvals/` — the generic
 *   freeze/approve/execute machinery and the approvals screen. This class
 *   writes the two §3.1 rows for one kind, so the CRM screens are
 *   approval-driven from their first commit instead of after S2-08 lands. When
 *   it does, the proposal and approval writes here move behind its API; the
 *   shape of the rows is already the one it defines.
 *
 * On approval the row is stamped again by `RetentionStamp`, so `retain_until`
 * is derived from the rule's clock *after* `deleted_at` is set — the ninety-day
 * undo window `crm_records` describes starts at the deletion, not at the
 * proposal.
 */
final class CrmDeletions
{
    /**
     * How long a proposed deletion stays approvable. A day rather than the
     * §11 minutes an outbound send gets: nothing leaves the Mac here, and the
     * person who proposed it may reasonably sleep on it. Long enough to think,
     * short enough that a forgotten proposal cannot be approved a month later
     * by somebody who no longer remembers the record.
     */
    public const int APPROVAL_WINDOW_HOURS = 24;

    public function __construct(
        private readonly ConnectionResolverInterface $connections,
        private readonly AuditLog $audit,
    ) {}

    /**
     * Freeze a deletion proposal for `$row`, or return the pending one that is
     * already there — a double-clicked Delete button proposes once.
     */
    public function propose(WorkspaceScope $scope, CrmEntity $entity, CrmModel $row, CarbonImmutable $now): ActionProposal
    {
        return $this->connections->connection()->transaction(function () use ($scope, $entity, $row, $now): ActionProposal {
            $existing = $this->pendingFor($scope, $entity, (string) $row->getKey(), $now);

            if ($existing instanceof ActionProposal) {
                return $existing;
            }

            $payload = self::payloadFor($entity, $row);

            $proposal = new ActionProposal;
            $proposal->forceFill([
                'organization_id' => $row->organization_id,
                'kind' => ProposalKind::CrmDeletion,
                'status' => ProposalStatus::Pending,
                'proposed_by' => "user:{$scope->actorUserId}",
                'requested_by_user_id' => $scope->actorUserId,
                'payload' => $payload,
                'proposal_hash' => ActionProposal::hashPayload($payload),
                'nonce' => bin2hex(random_bytes(32)),
                'idempotency_key' => (string) Str::ulid(),
                'frozen_at' => $now,
                'expires_at' => $now->addHours(self::APPROVAL_WINDOW_HOURS),
                // §3.1 lineage pointing at the system of record, which is also
                // what makes "is there already a pending deletion for this
                // row" an indexed lookup rather than a JSON query that two
                // database engines spell differently (ADR-002 D10).
                'source_id' => self::targetOf($entity, (string) $row->getKey()),
                'source_uri' => self::uriOf($entity, (string) $row->getKey()),
                'sensitivity_class' => SensitivityClass::Internal,
                'provenance' => Provenance::user($scope->actorUserId, ProvenanceOrigin::Manual),
                'retention_policy' => 'approval_record',
            ]);
            $proposal->save();

            $this->audit->record(
                action: 'crm.deletion_proposed',
                actor: "user:{$scope->actorUserId}",
                outcome: AuditLog::OUTCOME_OK,
                subjectType: $entity->newModel()::class,
                subjectId: (string) $row->getKey(),
                context: ['entity' => $entity->value, 'proposal_id' => (string) $proposal->getKey()],
                organizationId: $row->organization_id,
            );

            return $proposal;
        });
    }

    /**
     * Record the approval and perform the soft delete, in one transaction.
     *
     * @throws CrmDeletionRefused the proposal lapsed, was already decided, or its content no longer matches its hash
     */
    public function approve(WorkspaceScope $scope, CrmEntity $entity, CrmModel $row, User $approver, CarbonImmutable $now): void
    {
        $this->connections->connection()->transaction(function () use ($scope, $entity, $row, $approver, $now): void {
            $proposal = $this->pendingFor($scope, $entity, (string) $row->getKey(), $now);

            if (! $proposal instanceof ActionProposal) {
                throw CrmDeletionRefused::notApproved((string) $row->getKey());
            }

            // Fail closed (§11): pending, unexpired, and the frozen content
            // still hashes to what the approver is looking at.
            if (! $proposal->isApprovable($now)) {
                throw CrmDeletionRefused::notApprovable((string) $proposal->getKey());
            }

            $approval = new ActionApproval;
            $approval->forceFill([
                'organization_id' => $proposal->organization_id,
                'action_proposal_id' => $proposal->getKey(),
                'nonce' => $proposal->nonce,
                'proposal_hash' => $proposal->proposal_hash,
                'approver_user_id' => $approver->id,
                'approved_at' => $now,
                'expires_at' => $proposal->expires_at,
                'consumed_at' => $now,
                'sensitivity_class' => SensitivityClass::Internal,
                'provenance' => Provenance::user((string) $approver->id, ProvenanceOrigin::Manual),
                'retention_policy' => 'approval_record',
            ]);
            $approval->save();

            // A soft delete, never a hard one: the row keeps its retention
            // evidence and the purge job decides when it leaves. `delete()`
            // fills `deleted_at` on the instance, which is the clock
            // `crm_records` counts its undo window from, so the stamp that
            // follows reads the value the delete just wrote.
            $row->delete();
            RetentionStamp::apply($row, $now);
            $row->saveQuietly();

            $proposal->status = ProposalStatus::Executed;
            $proposal->executed_at = $now;
            $proposal->save();

            $this->audit->record(
                action: 'crm.deleted',
                actor: "user:{$approver->id}",
                outcome: AuditLog::OUTCOME_OK,
                subjectType: $row::class,
                subjectId: (string) $row->getKey(),
                context: [
                    'entity' => $entity->value,
                    'proposal_id' => (string) $proposal->getKey(),
                    'approval_id' => (string) $approval->getKey(),
                    'retention_policy' => (string) $row->retention_policy,
                ],
                organizationId: $row->organization_id,
            );
        });
    }

    /** Withdraw a proposed deletion. The record was never touched, so nothing is undone. */
    public function reject(WorkspaceScope $scope, CrmEntity $entity, CrmModel $row, User $approver, CarbonImmutable $now): void
    {
        $proposal = $this->pendingFor($scope, $entity, (string) $row->getKey(), $now);

        if (! $proposal instanceof ActionProposal) {
            return;
        }

        $proposal->status = ProposalStatus::Rejected;
        $proposal->rejected_at = $now;
        $proposal->rejected_by_user_id = (string) $approver->id;
        $proposal->rejection_reason = 'Withdrawn by the approver.';
        $proposal->save();

        $this->audit->record(
            action: 'crm.deletion_rejected',
            actor: "user:{$approver->id}",
            outcome: AuditLog::OUTCOME_OK,
            subjectType: $row::class,
            subjectId: (string) $row->getKey(),
            context: ['entity' => $entity->value, 'proposal_id' => (string) $proposal->getKey()],
            organizationId: $row->organization_id,
        );
    }

    /**
     * The pending, unexpired deletion proposals for a page of rows, keyed by
     * the record id the screen renders them against.
     *
     * @param  list<string>  $rowIds
     * @return array<string, ActionProposal>
     */
    public function pendingByRecord(WorkspaceScope $scope, CrmEntity $entity, array $rowIds, CarbonImmutable $now): array
    {
        if ($rowIds === []) {
            return [];
        }

        $targets = array_map(static fn (string $id): string => self::targetOf($entity, $id), $rowIds);
        $byRow = [];

        foreach ($this->pendingQuery($scope, $now)->whereIn('source_id', $targets)->get() as $proposal) {
            $byRow[self::rowIdOf((string) $proposal->source_id)] = $proposal;
        }

        return $byRow;
    }

    public function pendingFor(WorkspaceScope $scope, CrmEntity $entity, string $rowId, CarbonImmutable $now): ?ActionProposal
    {
        return $this->pendingQuery($scope, $now)
            ->where('source_id', self::targetOf($entity, $rowId))
            ->first();
    }

    /**
     * Scoped to the organization the scope names, like every other read in this
     * module: the proposals of one workspace are that workspace's.
     *
     * @return Builder<ActionProposal>
     */
    private function pendingQuery(WorkspaceScope $scope, CarbonImmutable $now): Builder
    {
        return ActionProposal::query()
            ->where('organization_id', $scope->organizationId)
            ->where('kind', ProposalKind::CrmDeletion->value)
            ->where('status', ProposalStatus::Pending->value)
            ->where('expires_at', '>', $now)
            ->orderByDesc('frozen_at');
    }

    /**
     * What the approver is approving, frozen: the record's identity and the
     * name it is shown under, which is what makes "delete Nordlund Logistik"
     * readable in a list. Nothing else — not the notes, not the addresses, not
     * a timeline. The `Redactor` pass the migration describes for this column
     * belongs to S2-08's writer; keeping the payload to references and one
     * label is what makes it unnecessary here rather than merely redundant.
     *
     * @return array<string, mixed>
     */
    private static function payloadFor(CrmEntity $entity, CrmModel $row): array
    {
        return [
            'action' => 'crm.delete',
            'entity' => $entity->value,
            'record_id' => (string) $row->getKey(),
            'organization_id' => $row->organization_id,
            'label' => (string) $row->getAttribute($entity->displayColumn()),
        ];
    }

    private static function targetOf(CrmEntity $entity, string $rowId): string
    {
        return "{$entity->value}:{$rowId}";
    }

    private static function rowIdOf(string $target): string
    {
        $parts = explode(':', $target, 2);

        return $parts[1] ?? $target;
    }

    private static function uriOf(CrmEntity $entity, string $rowId): string
    {
        return "lxai://crm/{$entity->value}/{$rowId}";
    }
}
