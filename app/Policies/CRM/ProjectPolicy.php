<?php

declare(strict_types=1);

namespace App\Policies\CRM;

use App\Policies\BusinessObjectPolicy;

/**
 * A piece of work for a customer. The customer reference is not the tenant
 * decision: a project's own `organization_id` is, so a project cannot be
 * reached by naming a customer in another organization.
 *
 * The tenant rule, the append-only rule and the "no admin bypass" rule all
 * live in `BusinessObjectPolicy`; this class only names the table they apply
 * to, so there is one policy per §3.1 business object and
 * `PolicySurfaceArchTest` can see it.
 *
 * @see BusinessObjectPolicy
 */
final class ProjectPolicy extends BusinessObjectPolicy {}
