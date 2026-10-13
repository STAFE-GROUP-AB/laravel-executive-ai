<?php

declare(strict_types=1);

namespace App\Approvals\Policies;

use App\AI\Tools\PolicyDecision;
use App\AI\Tools\ToolKind;
use App\AI\Tools\ToolRunOutcome;
use App\Models\AI\ToolRun;
use App\Models\Approvals\ActionProposal;
use App\Security\Audit\AuditLog;
use App\Security\Authorization\Gates\SurfaceContext;
use App\Security\Secrets\Redactor;
use App\Support\Data\Provenance;
use App\Support\Data\ProvenanceOrigin;
use App\Support\Data\SensitivityClass;
use Carbon\CarbonImmutable;

/**
 * Where a decision becomes evidence. Every verdict the gate reaches — allow and
 * refuse alike — is written twice, and the two rows answer different questions.
 *
 * **`tool_runs`** answers "did the gate run on this call". `policy_decision` is not
 * nullable, so a tool invocation that was never put to the gate has no
 * representable row (§4, S2-02's migration says so in its own docblock). The row is
 * written *before* anything could execute, with `outcome: denied` on a refusal and
 * `outcome: running` on an allow — the executor finishes it. An allow whose executor
 * then crashed leaves a `running` row, which is the honest record of what happened.
 *
 * **`audit_events`** answers "which rule decided, and why". It goes through
 * `App\Security\Audit\AuditLog` — the one writer of that table — rather than a
 * second audit path, so S2-06's `AuditCompletenessTest` covers these rows with the
 * same reader it uses for the five authorization surfaces. The context carries
 * references only: a rule name, a tool name, a `PolicyReason` value, a surface.
 * There is no branch on which an argument value can reach it.
 *
 * `tool_runs.arguments_redacted` is the one place argument *names* are kept, as the
 * sorted name list plus the schema problems — never the values. The column exists
 * for redacted arguments and the `Redactor` is applied on the way in (ADR-003 D7
 * row 4), but a policy record does not need the values at all, and a record that
 * does not hold them cannot leak them.
 */
final class PolicyJournal
{
    /** `audit_events.action` prefix for a gate decision, as `authz.` is for a policy. */
    public const string ACTION_PREFIX = 'policy.';

    public function __construct(
        private readonly AuditLog $audit,
        private readonly Redactor $redactor,
        private readonly SurfaceContext $surfaces,
    ) {}

    /**
     * @param  list<string>  $schemaProblems  `missing:`/`type:`/`unexpected:` names, never values
     */
    public function record(
        ProposedAction $action,
        ToolCallContext $context,
        PolicyVerdict $verdict,
        ToolKind $toolKind,
        array $schemaProblems = [],
    ): PolicyVerdict {
        $now = CarbonImmutable::now('UTC');
        $allowed = $verdict->permitsExecution();

        $toolRun = ToolRun::query()->create([
            'organization_id' => $context->organizationId,
            'ai_run_id' => $context->aiRunId,
            'trace_id' => $context->traceId,
            'tool_name' => $action->toolName,
            'tool_kind' => $toolKind,
            'step' => $context->step,
            'arguments_redacted' => $this->redactor->redactArray([
                'argument_names' => $action->argumentNames(),
                'payload_hash' => $action->currentHash(),
                ...($schemaProblems === [] ? [] : ['schema_problems' => $schemaProblems]),
            ]),
            'policy_decision' => $allowed ? PolicyDecision::Allowed : PolicyDecision::Denied,
            'policy_reason' => $this->policyReason($verdict),
            'action_proposal_id' => $verdict->proposalId,
            'outcome' => $allowed ? ToolRunOutcome::Running : ToolRunOutcome::Denied,
            'started_at' => $now,
            'finished_at' => $allowed ? null : $now,
            'duration_ms' => $allowed ? null : 0,
            'sensitivity_class' => ($action->dataClass ?? SensitivityClass::Internal)->value,
            'provenance' => Provenance::job('PolicyGate', ProvenanceOrigin::Sync, $context->traceId),
        ]);

        $this->audit->record(
            action: self::ACTION_PREFIX.$action->toolName,
            actor: $context->actor(),
            outcome: $allowed ? AuditLog::OUTCOME_OK : AuditLog::OUTCOME_REFUSED,
            subjectType: $verdict->proposalId === null ? null : ActionProposal::class,
            subjectId: $verdict->proposalId,
            context: [
                'rule' => $verdict->rule,
                'tool' => $action->toolName,
                'surface' => $this->surfaces->current()->value,
                'result' => $allowed ? 'allowed' : 'refused',
                'reason' => $verdict->reason->value,
            ],
            organizationId: $context->organizationId,
        );

        return $verdict->withToolRun((string) $toolRun->getKey());
    }

    /**
     * `<rule>:<reason>` — both halves, because "which rule" and "why" are different
     * facts and the column holds 200 characters, enough for either vocabulary.
     */
    private function policyReason(PolicyVerdict $verdict): string
    {
        return substr($verdict->rule.':'.$verdict->reason->value, 0, 200);
    }
}
