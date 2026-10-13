<?php

declare(strict_types=1);

namespace App\CRM\Deletions;

use App\Approvals\Policies\PolicyDeniedException;

/**
 * A CRM deletion was asked to execute without a valid approval behind it: the
 * proposal has lapsed, it was already decided, or its frozen content no longer
 * hashes to what the approver saw (§11 "fail closed").
 *
 * It extends `PolicyDeniedException` like every other server-side refusal, so
 * one catch at a surface turns it into a message rather than a stack trace, and
 * the message names no record content.
 */
final class CrmDeletionRefused extends PolicyDeniedException
{
    public readonly string $proposalId;

    private function __construct(string $message, string $proposalId)
    {
        parent::__construct($message, 'crm.deletion_approval');

        $this->proposalId = $proposalId;
    }

    public static function notApprovable(string $proposalId): self
    {
        return new self('This deletion can no longer be approved: it has lapsed or was already decided.', $proposalId);
    }

    public static function notApproved(string $proposalId): self
    {
        return new self('This record is only deleted once its deletion has been approved.', $proposalId);
    }
}
