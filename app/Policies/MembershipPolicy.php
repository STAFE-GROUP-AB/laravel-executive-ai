<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Who belongs to an organization. A member may read the memberships of their
 * own organizations — that is how the PWA shows who else is there — and
 * changing one is S2-19's admin surface, not a member's.
 *
 * The tenant rule, the append-only rule and the "no admin bypass" rule all
 * live in `BusinessObjectPolicy`; this class only names the table they apply
 * to, so there is one policy per §3.1 business object and
 * `PolicySurfaceArchTest` can see it.
 *
 * @see BusinessObjectPolicy
 */
final class MembershipPolicy extends BusinessObjectPolicy {}
