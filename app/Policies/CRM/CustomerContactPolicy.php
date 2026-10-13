<?php

declare(strict_types=1);

namespace App\Policies\CRM;

use App\Policies\BusinessObjectPolicy;

/**
 * The customer↔contact link, a business object in its own right (§3.1). A
 * pivot row with an `organization_id` needs a policy as much as any other row:
 * a link is how a foreign contact would be reached through a local customer.
 *
 * The tenant rule, the append-only rule and the "no admin bypass" rule all
 * live in `BusinessObjectPolicy`; this class only names the table they apply
 * to, so there is one policy per §3.1 business object and
 * `PolicySurfaceArchTest` can see it.
 *
 * @see BusinessObjectPolicy
 */
final class CustomerContactPolicy extends BusinessObjectPolicy {}
