<?php

declare(strict_types=1);

namespace App\Policies\AI;

use App\Policies\BusinessObjectPolicy;

/**
 * One model run with ADR-004 D7's provenance. Append-only: the run record is
 * what an incident review reads, and only the retention job removes it.
 *
 * The tenant rule, the append-only rule and the "no admin bypass" rule all
 * live in `BusinessObjectPolicy`; this class only names the table they apply
 * to, so there is one policy per §3.1 business object and
 * `PolicySurfaceArchTest` can see it.
 *
 * @see BusinessObjectPolicy
 */
final class AiRunPolicy extends BusinessObjectPolicy
{
    protected const bool APPEND_ONLY = true;
}
