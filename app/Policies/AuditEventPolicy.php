<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * The audit trail itself (§10). Readable by the organization it concerns and
 * append-only through every surface: the table is not tamper-evident in v1
 * (RR-05), so the least the policy layer can do is refuse to be the tool.
 *
 * The tenant rule, the append-only rule and the "no admin bypass" rule all
 * live in `BusinessObjectPolicy`; this class only names the table they apply
 * to, so there is one policy per §3.1 business object and
 * `PolicySurfaceArchTest` can see it.
 *
 * @see BusinessObjectPolicy
 */
final class AuditEventPolicy extends BusinessObjectPolicy
{
    protected const bool APPEND_ONLY = true;
}
