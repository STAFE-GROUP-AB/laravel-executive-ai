<?php

declare(strict_types=1);

namespace App\Policies\CRM;

use App\Policies\BusinessObjectPolicy;

/**
 * A task. Assignment does not widen reach: a task assigned to a user who is
 * not a member of the task's organization is still refused — membership is the
 * only thing that grants reach.
 *
 * The tenant rule, the append-only rule and the "no admin bypass" rule all
 * live in `BusinessObjectPolicy`; this class only names the table they apply
 * to, so there is one policy per §3.1 business object and
 * `PolicySurfaceArchTest` can see it.
 *
 * @see BusinessObjectPolicy
 */
final class TaskPolicy extends BusinessObjectPolicy {}
