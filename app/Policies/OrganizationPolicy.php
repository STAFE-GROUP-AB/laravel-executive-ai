<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Database\Eloquent\Model;

/**
 * The tenant row itself. The only policy whose organization is its own key
 * rather than an `organization_id` column, which is what `organizationIdOf()`
 * exists for. Renaming or deleting an organization is S2-19's admin surface.
 *
 * The tenant rule, the append-only rule and the "no admin bypass" rule all
 * live in `BusinessObjectPolicy`; this class only names the table they apply
 * to, so there is one policy per §3.1 business object and
 * `PolicySurfaceArchTest` can see it.
 *
 * @see BusinessObjectPolicy
 */
final class OrganizationPolicy extends BusinessObjectPolicy
{
    /** The tenant row answers with its own key; see BusinessObjectPolicy. */
    protected function organizationIdOf(Model $object): ?string
    {
        $key = $object->getKey();

        return $key === null ? null : (string) $key;
    }
}
