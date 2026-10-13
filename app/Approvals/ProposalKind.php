<?php

declare(strict_types=1);

namespace App\Approvals;

/**
 * The outbound actions blueprint §11 makes approval-driven: "Mail send, CRM
 * deletion, GitHub write och externa formulär alltid approval-driven", plus the
 * ADR-005 UI action. One `action_proposals` row per proposed action, one kind per
 * row, and `payload` carries the kind's frozen content.
 */
enum ProposalKind: string
{
    case UiAction = 'ui_action';
    case OutgoingEmail = 'outgoing_email';
    case CrmDeletion = 'crm_deletion';
    case GithubWrite = 'github_write';
    case ExternalForm = 'external_form';

    /**
     * Whether the kind uses the ADR-005 D1.6 target columns (app bundle id, window
     * title, element role and label, action type, redacted value, screenshot hash).
     * Only the automation path does; the others describe their target in `payload`.
     */
    public function targetsAUiElement(): bool
    {
        return $this === self::UiAction;
    }
}
