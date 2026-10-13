<?php

declare(strict_types=1);

namespace App\Policies\CRM;

use App\Policies\BusinessObjectPolicy;

/**
 * One address of a contact. Reachable with the contact, refused with it; an
 * address is the identifier cross-account access is attempted with (T-73), so
 * it gets its own policy rather than riding on the contact's.
 *
 * The tenant rule, the append-only rule and the "no admin bypass" rule all
 * live in `BusinessObjectPolicy`; this class only names the table they apply
 * to, so there is one policy per §3.1 business object and
 * `PolicySurfaceArchTest` can see it.
 *
 * @see BusinessObjectPolicy
 */
final class ContactEmailPolicy extends BusinessObjectPolicy {}
