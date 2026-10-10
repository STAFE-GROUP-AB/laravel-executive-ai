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
            'actor' => $actor,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'outcome' => $outcome,
            'context' => $context === [] ? null : $this->redactor->redactArray($context),
            'occurred_at' => CarbonImmutable::now('UTC'),
            'sensitivity_class' => 'internal',
            'provenance' => $this->provenance($actor),
        ]);
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
