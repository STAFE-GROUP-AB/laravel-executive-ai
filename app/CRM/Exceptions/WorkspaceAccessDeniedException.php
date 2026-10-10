<?php

declare(strict_types=1);

namespace App\CRM\Exceptions;

use App\Approvals\Policies\PolicyDeniedException;

/**
 * The actor in the query's WorkspaceScope has no membership in the organization it
 * names (or the membership forbids the entity). Raised before any row is read: a
 * repository must not answer with an empty page where a denial is the truth, so the
 * §11 cross-account negative tests can distinguish "nothing there" from "refused".
 */
final class WorkspaceAccessDeniedException extends PolicyDeniedException
{
    public function __construct(
        public readonly string $organizationId,
        public readonly string $actorUserId,
    ) {
        parent::__construct(
            message: 'The current user may not read this organization.',
            policy: 'crm.workspace_scope',
        );
    }
}
