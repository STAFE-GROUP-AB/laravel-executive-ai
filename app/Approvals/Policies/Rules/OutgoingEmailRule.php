<?php

declare(strict_types=1);

namespace App\Approvals\Policies\Rules;

use App\AI\Tools\ToolKind;
use App\Approvals\ProposalKind;
use App\Support\Data\SensitivityClass;

/**
 * `email.send` — the action §16.7 is written about, and the one the frozen S0-19
 * corpus attacks (`tool_call:email.send` is the forbidden effect every case names).
 *
 * Every declaration below is the §16.7 refusal expressed as data:
 *
 *  - `from` is **not** in the schema. The From identity is a property of the
 *    connected account, resolved from the account row, and §5.6's "en agent får
 *    aldrig byta From-identitet" means a proposed `from` is not a value to validate
 *    but a field that may not exist. The schema refuses unexpected arguments, so
 *    the T-05 case's `from` and `reply_to` are `unexpected:from`,
 *    `unexpected:reply_to` — a refusal naming the field, before any address
 *    comparison.
 *  - destinations must be among the recipients the **frozen** payload named, so an
 *    address that arrived in a mail body cannot become one (`recipient_added`).
 *  - the account scope is required, so account A can never send through account B.
 *  - approval is required, so a proposal with no `action_approvals` row behind it is
 *    refused and the refusal names the missing approval.
 */
final readonly class OutgoingEmailRule implements ActionRule
{
    public const string TOOL = 'email.send';

    public function name(): string
    {
        return 'outgoing_email';
    }

    public function toolNames(): array
    {
        return [self::TOOL];
    }

    public function kind(): ProposalKind
    {
        return ProposalKind::OutgoingEmail;
    }

    public function toolKind(): ToolKind
    {
        return ToolKind::Builtin;
    }

    public function schema(): ArgumentSchema
    {
        return ArgumentSchema::of(
            required: [
                'connection_id' => ArgumentType::Text,
                'to' => ArgumentType::TextList,
                'subject' => ArgumentType::Text,
                'body' => ArgumentType::Text,
            ],
            optional: [
                'cc' => ArgumentType::TextList,
                'bcc' => ArgumentType::TextList,
                'in_reply_to_external_id' => ArgumentType::Text,
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
        return DestinationRule::emailRecipients();
    }

    /**
     * One send per run, because a second send in the same run is a second action and
     * needs its own frozen proposal and its own approval. 64 KiB is the ADR-005 D4.8
     * tool-result ceiling applied to the other direction: a body larger than that
     * was not read by the approver in any meaningful sense. Twenty recipients is the
     * line between a reply and a broadcast.
     */
    public function budget(): ActionBudget
    {
        return new ActionBudget(maxCallsPerRun: 1, maxCanonicalBytes: 65536, maxDestinations: 20);
    }

    public function requiresApproval(): bool
    {
        return true;
    }

    public function requiresAccountScope(): bool
    {
        return true;
    }
}
