<?php

declare(strict_types=1);

namespace App\Policies\AI;

use App\Policies\BusinessObjectPolicy;

/**
 * One tool invocation inside a run, carrying the `policy_decision` the
 * server-side gate made (§4). Append-only — a tool run whose recorded decision
 * could be edited afterwards would be worthless as evidence that the gate ran.
 *
 * The tenant rule, the append-only rule and the "no admin bypass" rule all
 * live in `BusinessObjectPolicy`; this class only names the table they apply
 * to, so there is one policy per §3.1 business object and
 * `PolicySurfaceArchTest` can see it.
 *
 * @see BusinessObjectPolicy
 */
final class ToolRunPolicy extends BusinessObjectPolicy
{
    protected const bool APPEND_ONLY = true;
}
