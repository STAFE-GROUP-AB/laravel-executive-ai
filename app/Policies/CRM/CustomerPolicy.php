<?php

declare(strict_types=1);

namespace App\Policies\CRM;

use App\Policies\BusinessObjectPolicy;

/**
 * A customer with a legal identity (§3.2). Two customers may share an email
 * domain, so the domain is never a key and never a tenant decision — the
 * `organization_id` on the row is.
 *
 * The tenant rule, the append-only rule and the "no admin bypass" rule all
 * live in `BusinessObjectPolicy`; this class only names the table they apply
 * to, so there is one policy per §3.1 business object and
 * `PolicySurfaceArchTest` can see it.
 *
 * @see BusinessObjectPolicy
 */
final class CustomerPolicy extends BusinessObjectPolicy {}
