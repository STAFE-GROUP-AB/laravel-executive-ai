<?php

declare(strict_types=1);

namespace App\Policies\AI;

use App\Policies\BusinessObjectPolicy;

/**
 * What a memory was derived from. Append-only, for the same reason as
 * SourceRecordPolicy: a memory's provenance is only useful if it cannot be
 * rewritten after the fact.
 *
 * The tenant rule, the append-only rule and the "no admin bypass" rule all
 * live in `BusinessObjectPolicy`; this class only names the table they apply
 * to, so there is one policy per §3.1 business object and
 * `PolicySurfaceArchTest` can see it.
 *
 * @see BusinessObjectPolicy
 */
final class MemorySourcePolicy extends BusinessObjectPolicy
{
    protected const bool APPEND_ONLY = true;
}
