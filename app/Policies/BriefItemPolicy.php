<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * One item of a frozen brief version. It is reachable exactly as its brief
 * is: the items carry the same `organization_id`, so a brief that is a 403 can
 * never have a readable item (the IDOR shape a nested id invites).
 *
 * The tenant rule, the append-only rule and the "no admin bypass" rule all
 * live in `BusinessObjectPolicy`; this class only names the table they apply
 * to, so there is one policy per §3.1 business object and
 * `PolicySurfaceArchTest` can see it.
 *
 * @see BusinessObjectPolicy
 */
final class BriefItemPolicy extends BusinessObjectPolicy {}
