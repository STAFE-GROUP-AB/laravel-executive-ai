<?php

declare(strict_types=1);

namespace App\Policies\AI;

use App\Policies\BusinessObjectPolicy;

/**
 * A configured model provider with its ADR-004 D5 region evidence. The
 * credential never lives here (ADR-003: the Supervisor's Keychain does), so
 * this policy guards the configuration, not the key.
 *
 * The tenant rule, the append-only rule and the "no admin bypass" rule all
 * live in `BusinessObjectPolicy`; this class only names the table they apply
 * to, so there is one policy per §3.1 business object and
 * `PolicySurfaceArchTest` can see it.
 *
 * @see BusinessObjectPolicy
 */
final class AiProviderPolicy extends BusinessObjectPolicy {}
