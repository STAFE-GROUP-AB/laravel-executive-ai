<?php

declare(strict_types=1);

namespace App\Policies\AI;

use App\Policies\BusinessObjectPolicy;

/**
 * One model a provider exposes, with its capabilities snapshot. Composes with
 * the S2-13 refusals rather than repeating them: this answers whether the user
 * may read the row, `ModelRouter` answers whether the privacy mode permits a run.
 *
 * The tenant rule, the append-only rule and the "no admin bypass" rule all
 * live in `BusinessObjectPolicy`; this class only names the table they apply
 * to, so there is one policy per §3.1 business object and
 * `PolicySurfaceArchTest` can see it.
 *
 * @see BusinessObjectPolicy
 */
final class AiModelPolicy extends BusinessObjectPolicy {}
