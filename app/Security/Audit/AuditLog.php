<?php

declare(strict_types=1);

namespace App\Security\Audit;

use App\Models\AuditEvent;
use App\Security\Secrets\Redactor;
use App\Support\Data\Provenance;
use App\Support\Data\ProvenanceOrigin;
use Carbon\CarbonImmutable;

/**
 * The one writer of `audit_events` (§10, §11). Every context array passes
 * through the Redactor before insert (ADR-003 D7 row 8), so a caller that
 * accidentally includes a credential-shaped value still writes a redacted
 * row. Callers are expected not to pass secrets at all; redaction is the
 * second layer, not the design.
 *
 * Actor grammar is the §3.1 provenance grammar: `user:<ulid>` for a person,
 * `job:<Name>` for a process (an artisan command, a scheduler entry).
 */
final class AuditLog
{
    public const string OUTCOME_OK = 'ok';

    public const string OUTCOME_REFUSED = 'refused';

    public const string OUTCOME_FAILED = 'failed';

    /**
     * The column widths of `create_audit_events_table`, so a value too long
     * for one of them is shortened here instead of throwing at the insert.
     *
     * This is not tidiness. MySQL in strict mode rejects an over-long string
     * (`SQLSTATE[22001]`) where SQLite silently accepts it, so without this a
     * refusal whose subject happens to have a long class name would be a 500
     * with no audit row on the `server` profile and a 403 with one on `local`
     * — the two profiles disagreeing about whether the refusal is on the
     * record. Found by the `cross-account` group's MySQL leg (S2-06), where an
     * anonymous test model produced a 140-character class name.
     */
    private const int ACTOR_LIMIT = 200;

    private const int ACTION_LIMIT = 100;

    private const int SUBJECT_TYPE_LIMIT = 100;

    private const int SUBJECT_ID_LENGTH = 26;

    public function __construct(private readonly Redactor $redactor) {}

    /** @param array<string, mixed> $context */
    public function record(
        string $action,
        string $actor,
        string $outcome,
        ?string $subjectType = null,
        ?string $subjectId = null,
        array $context = [],
        ?string $organizationId = null,
    ): AuditEvent {
        return AuditEvent::query()->create([
            'organization_id' => $organizationId,
            'actor' => $this->head($actor, self::ACTOR_LIMIT),
            'action' => $this->head($action, self::ACTION_LIMIT),
            'subject_type' => $this->tail($subjectType, self::SUBJECT_TYPE_LIMIT),
            'subject_id' => $this->identifier($subjectId),
            'outcome' => $outcome,
            'context' => $context === [] ? null : $this->redactor->redactArray($context),
            'occurred_at' => CarbonImmutable::now('UTC'),
            'sensitivity_class' => 'internal',
            'provenance' => $this->provenance($actor),
        ]);
    }

    /** `actor` and `action` are our own short vocabularies; the front is the identity. */
    private function head(string $value, int $limit): string
    {
        return strlen($value) <= $limit ? $value : substr($value, 0, $limit);
    }

    /**
     * `subject_type` is a class name, so the **end** is the informative part:
     * `…\Approvals\ActionProposal` says more than `App\Models\Approvals\Act`.
     */
    private function tail(?string $value, int $limit): ?string
    {
        if ($value === null || strlen($value) <= $limit) {
            return $value;
        }

        return substr($value, -$limit);
    }

    /**
     * A ULID or nothing. Every id in this schema is 26 characters, and a
     * *truncated* identifier is worse than an absent one — it looks like a row
     * that can be looked up and is not one. The subject is still identified by
     * `subject_type`, and the context carries the rest.
     */
    private function identifier(?string $value): ?string
    {
        return $value !== null && strlen($value) === self::SUBJECT_ID_LENGTH ? $value : null;
    }

    /** @return array{origin: string, actor: string, recorded_at: string, trace_id: ?string} */
    private function provenance(string $actor): array
    {
        if (str_starts_with($actor, 'user:')) {
            return Provenance::user(substr($actor, 5));
        }

        $jobName = str_starts_with($actor, 'job:') ? substr($actor, 4) : $actor;

        return Provenance::job($jobName, ProvenanceOrigin::Manual);
    }
}
