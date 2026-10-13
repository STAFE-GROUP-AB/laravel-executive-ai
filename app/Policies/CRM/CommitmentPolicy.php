<?php

declare(strict_types=1);

namespace App\Policies\CRM;

use App\Policies\BusinessObjectPolicy;

/**
 * Something somebody promised, extracted from a communication. Reachable with
 * the communication's organization and refused with it.
 *
 * The tenant rule, the append-only rule and the "no admin bypass" rule all
 * live in `BusinessObjectPolicy`; this class only names the table they apply
 * to, so there is one policy per §3.1 business object and
 * `PolicySurfaceArchTest` can see it.
 *
 * @see BusinessObjectPolicy
 */
final class CommitmentPolicy extends BusinessObjectPolicy {}
