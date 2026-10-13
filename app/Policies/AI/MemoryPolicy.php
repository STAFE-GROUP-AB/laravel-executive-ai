<?php

declare(strict_types=1);

namespace App\Policies\AI;

use App\Policies\BusinessObjectPolicy;

/**
 * A durable memory the assistant may use. Scoped to the organization it was
 * learned in: a memory is a summary of that organization's data, so reading one
 * across the boundary is reading the data (T-73).
 *
 * The tenant rule, the append-only rule and the "no admin bypass" rule all
 * live in `BusinessObjectPolicy`; this class only names the table they apply
 * to, so there is one policy per §3.1 business object and
 * `PolicySurfaceArchTest` can see it.
 *
 * @see BusinessObjectPolicy
 */
final class MemoryPolicy extends BusinessObjectPolicy {}
