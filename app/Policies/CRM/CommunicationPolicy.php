<?php

declare(strict_types=1);

namespace App\Policies\CRM;

use App\Policies\BusinessObjectPolicy;

/**
 * An email, meeting or call that happened. The mail account it came from is
 * scoped per organization (§5.6), and this policy is the per-row half of that
 * promise: mail fetched through account A is unreachable from organization B.
 *
 * The tenant rule, the append-only rule and the "no admin bypass" rule all
 * live in `BusinessObjectPolicy`; this class only names the table they apply
 * to, so there is one policy per §3.1 business object and
 * `PolicySurfaceArchTest` can see it.
 *
 * @see BusinessObjectPolicy
 */
final class CommunicationPolicy extends BusinessObjectPolicy {}
