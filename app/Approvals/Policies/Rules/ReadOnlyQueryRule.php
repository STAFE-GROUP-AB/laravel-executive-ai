<?php

declare(strict_types=1);

namespace App\Approvals\Policies\Rules;

use App\AI\Tools\ToolKind;
use App\Approvals\ProposalKind;
use App\Support\Data\SensitivityClass;

/**
 * The three read-only tools ADR-005 D4.10 names for v1 — `crm.search`,
 * `tasks.list`, `brief.read` — each annotated `#[IsReadOnly]` there, each already
 * running the row policy through `GatedTool` (S2-06).
 *
 * This is the one rule that does **not** require an approval, and the reason it is
 * safe to say so is that it is the only kind of action for which "nothing was
 * executed" and "the action happened" are the same outcome for the outside world: a
 * read reaches no destination, freezes no proposal and changes no row. Approval
 * exists to put a human between the model and an irreversible effect; a query has
 * none.
 *
 * It still passes all seven checks. In particular:
 *
 *  - `DestinationRule::none()` — a query that somehow carried a recipient is
 *    refused, which is what makes "read-only" a property of the gate rather than of
 *    the tool's good intentions;
 *  - the schema refuses the §16.7 exfiltration case's arguments outright: that case
 *    proposes `crm.search` with `{query: '*', include: ['contacts',
 *    'contract_values']}`, and `include` is not a declared argument, so the call is
 *    `unexpected:include` and the read never happens. The corpus requires the
 *    refusal to precede data gathering, and refusing on the schema is what puts it
 *    there;
 *  - `App\Security\Authorization\Gates\ToolAuthorizer` still decides whether this
 *    user may touch the rows the query names. The gate answers "is this a sensible
 *    call at all"; the policy answers "may this principal touch this row". Both must
 *    pass, and neither substitutes for the other.
 */
final readonly class ReadOnlyQueryRule implements ActionRule
{
    public const string CRM_SEARCH = 'crm.search';

    public const string TASKS_LIST = 'tasks.list';

    public const string BRIEF_READ = 'brief.read';

    public function name(): string
    {
        return 'read_only_query';
    }

    public function toolNames(): array
    {
        return [self::CRM_SEARCH, self::TASKS_LIST, self::BRIEF_READ];
    }

    public function kind(): ?ProposalKind
    {
        return null;
    }

    public function toolKind(): ToolKind
    {
        return ToolKind::Builtin;
    }

    public function schema(): ArgumentSchema
    {
        return ArgumentSchema::of(
            required: [],
            optional: [
                'query' => ArgumentType::Text,
                'customer_id' => ArgumentType::Text,
                'project_id' => ArgumentType::Text,
                'brief_id' => ArgumentType::Text,
                'limit' => ArgumentType::Integer,
            ],
        );
    }

    public function dataClasses(): array
    {
        return [
            SensitivityClass::Public,
            SensitivityClass::Internal,
            SensitivityClass::Confidential,
            SensitivityClass::Personal,
        ];
    }

    public function destinations(): DestinationRule
    {
        return DestinationRule::none();
    }

    /**
     * Eight reads per run is enough for a brief and short of a sweep; 2 KiB bounds
     * a query, not a result.
     */
    public function budget(): ActionBudget
    {
        return new ActionBudget(maxCallsPerRun: 8, maxCanonicalBytes: 2048, maxDestinations: 0);
    }

    public function requiresApproval(): bool
    {
        return false;
    }

    public function requiresAccountScope(): bool
    {
        return false;
    }
}
