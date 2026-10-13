<?php

declare(strict_types=1);

namespace App\Policies\Approvals;

use App\Policies\BusinessObjectPolicy;

/**
 * The approver's decision, with the proposal hash they saw. Append-only: an
 * approval that could be edited after the fact would let an approved action be
 * replayed against different content, which is the one thing the hash exists to
 * prevent.
 *
 * The tenant rule, the append-only rule and the "no admin bypass" rule all
 * live in `BusinessObjectPolicy`; this class only names the table they apply
 * to, so there is one policy per §3.1 business object and
 * `PolicySurfaceArchTest` can see it.
 *
 * @see BusinessObjectPolicy
 */
final class ActionApprovalPolicy extends BusinessObjectPolicy
{
    protected const bool APPEND_ONLY = true;
}
