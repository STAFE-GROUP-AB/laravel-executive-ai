<?php

declare(strict_types=1);

namespace App\CRM;

/**
 * Who is asking, for which organization. Every query carries one; a repository must
 * return rows only from `organizationId`, and only ones `actorUserId`'s membership
 * may see (blueprint §3.1 `organization_id`, §11 cross-account negative tests,
 * ADR-002 D10 "identical ids on both profiles").
 *
 * The scope is produced by the authenticated request or the job's stored context,
 * never from model output or external text.
 */
final readonly class WorkspaceScope
{
    public function __construct(
        public string $organizationId,
        public string $actorUserId,
    ) {}
}
