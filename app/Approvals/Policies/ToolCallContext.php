<?php

declare(strict_types=1);

namespace App\Approvals\Policies;

use App\Support\Data\SensitivityClass;

/**
 * The scope one tool call is being decided inside: which organization, which run,
 * which connected account, on whose behalf.
 *
 * It is a separate object from `ProposedAction` because of where each comes from,
 * and that difference is the whole of §4. The **action** is the model's: it carries
 * the tool name and the arguments the model proposed. The **context** is the
 * server's: the organization comes from the authenticated principal, the account
 * from the connection the run was started on, the trace from the `ai_runs` row. A
 * model that wants another organization's data can say so in the action; it cannot
 * say so here, because nothing it emits reaches this object.
 *
 * `PolicyGate` compares the two (`PolicyReason::ScopeViolation`), which is how
 * §5.6 criterion 4 — "account A can never read or send through account B" — is a
 * check rather than a convention.
 */
final readonly class ToolCallContext
{
    /**
     * `$dataClass` is the §3.1 sensitivity class the run is operating at, declared
     * by whoever started it. It is here rather than on `ProposedAction` for the same
     * reason as the organization: the model must not be able to reclassify the data
     * it is touching by saying so in an argument (§4 input 4).
     */
    public function __construct(
        public string $organizationId,
        public string $traceId,
        public ?string $actorUserId = null,
        public ?string $accountId = null,
        public ?string $aiRunId = null,
        public int $step = 0,
        public SensitivityClass $dataClass = SensitivityClass::Internal,
    ) {}

    /**
     * The §3.1 provenance actor for the audit row. A tool call always happens on
     * somebody's behalf; when the dispatching user is not known the gate still
     * records the decision, as the gate itself rather than as a person.
     */
    public function actor(): string
    {
        return $this->actorUserId === null ? 'job:PolicyGate' : "user:{$this->actorUserId}";
    }
}
