<?php

declare(strict_types=1);

namespace App\Security\Authorization\Gates;

use App\Models\User;
use App\Security\Authorization\Tenancy;
use Illuminate\Contracts\Auth\Access\Gate;

/**
 * The abilities that are not about one row: the PWA's own pages, the Livewire
 * actions on the user's own account, and the tenant decision for a table that
 * has no Eloquent model yet.
 *
 * ## Why the page abilities do not check membership
 *
 * It would be tempting to make `viewBriefs` require a membership, and it would
 * be wrong. `docs/architecture/pwa.md` §2 is explicit: "There is no admin
 * bypass; **a user without a membership sees the empty states**" — a 200 with
 * nothing in it, not a 403. The tenant decision belongs to the row, not to the
 * page: `BriefCatalog::visibleTo()` answers *which* briefs, `BriefPolicy`
 * answers *this* brief, and a page ability that tried to answer either would
 * be a second, parallel tenant rule to keep in step with them.
 *
 * So these abilities answer a narrower question — "is this a signed-in user of
 * this install" — and they exist for three reasons that are not about tenancy:
 *
 * 1. the surface runs a **named** check, so `PolicySurfaceArchTest` can see
 *    that it ran one and CI fails when a new page or action does not;
 * 2. a mutating action leaves an **audit row** naming the actor, the ability
 *    and the outcome (T-46) — which is the whole reason `revokeDevice` is an
 *    ability and not a bare `auth` middleware;
 * 3. there is **one place** for the next rule to land (a suspended account, a
 *    privacy mode that closes a surface) instead of a condition sprinkled
 *    across controllers.
 *
 * `accessOrganization` is the one gate here that *is* about tenancy, because
 * `TenantRowGuard` has read the row's `organization_id` before it asks.
 */
final class SurfaceGates
{
    public static function define(Gate $gate): void
    {
        $gate->define(
            Abilities::ACCESS_ORGANIZATION,
            static fn (User $user, ?string $organizationId): bool => Tenancy::isMember($user, $organizationId),
        );

        foreach (self::signedInAbilities() as $ability) {
            $gate->define($ability, static fn (User $user): bool => true);
        }
    }

    /**
     * Everything in `Abilities::SURFACES` except the tenant gate, which takes
     * an organization id rather than deciding on the session alone.
     *
     * @return list<string>
     */
    public static function signedInAbilities(): array
    {
        return array_values(array_filter(
            Abilities::SURFACES,
            static fn (string $ability): bool => $ability !== Abilities::ACCESS_ORGANIZATION,
        ));
    }
}
