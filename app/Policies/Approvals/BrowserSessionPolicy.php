<?php

declare(strict_types=1);

namespace App\Policies\Approvals;

use App\Policies\BusinessObjectPolicy;

/**
 * One automation session (ADR-005). The session is where computer-use actions
 * are proposed from, so it is scoped like everything else and never shared
 * between organizations.
 *
 * The tenant rule, the append-only rule and the "no admin bypass" rule all
 * live in `BusinessObjectPolicy`; this class only names the table they apply
 * to, so there is one policy per §3.1 business object and
 * `PolicySurfaceArchTest` can see it.
 *
 * @see BusinessObjectPolicy
 */
final class BrowserSessionPolicy extends BusinessObjectPolicy {}
