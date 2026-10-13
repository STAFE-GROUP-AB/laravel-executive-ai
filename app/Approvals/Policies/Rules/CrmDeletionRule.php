<?php

declare(strict_types=1);

namespace App\Approvals\Policies\Rules;

use App\AI\Tools\ToolKind;
use App\Approvals\ProposalKind;
use App\Support\Data\SensitivityClass;

/**
 * `crm.delete` — §11's "CRM deletion … alltid approval-driven".
 *
 * It reaches nothing outside the machine, so `DestinationRule::none()`, and that is
 * a check rather than a shrug: a deletion that somehow carried a recipient would be
 * refused. Approval is still required, because the damage of a deletion is local
 * and permanent rather than outbound.
 *
 * The §16.7 privilege-escalation case ends with exactly this call
 * (`crm.delete` for the customer the hostile mail arrived on). It is refused here
 * for the missing approval, after the two calls before it — `settings.write` and
 * `approvals.grant` — are refused for having no rule at all.
 */
final readonly class CrmDeletionRule implements ActionRule
{
    public const string TOOL = 'crm.delete';

    public function name(): string
    {
        return 'crm_deletion';
    }

    public function toolNames(): array
    {
        return [self::TOOL];
    }

    public function kind(): ProposalKind
    {
        return ProposalKind::CrmDeletion;
    }

    public function toolKind(): ToolKind
    {
        return ToolKind::Builtin;
    }

    public function schema(): ArgumentSchema
    {
        return ArgumentSchema::of(
            required: [
                'object_type' => ArgumentType::Text,
                'object_id' => ArgumentType::Text,
            ],
            optional: ['reason' => ArgumentType::Text],
        );
    }

    public function dataClasses(): array
    {
        return [
            SensitivityClass::Internal,
            SensitivityClass::Confidential,
            SensitivityClass::Personal,
        ];
    }

    public function destinations(): DestinationRule
    {
        return DestinationRule::none();
    }

    public function budget(): ActionBudget
    {
        return new ActionBudget(maxCallsPerRun: 1, maxCanonicalBytes: 4096, maxDestinations: 0);
    }

    public function requiresApproval(): bool
    {
        return true;
    }

    /** A row belongs to an organization, not to a connected account. */
    public function requiresAccountScope(): bool
    {
        return false;
    }
}
