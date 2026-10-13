<?php

declare(strict_types=1);

namespace App\Security\Authorization;

use App\Models\Membership;
use App\Models\MembershipRole;
use App\Models\User;

/**
 * The tenant boundary, in one place (blueprint §11, threat model T-73).
 *
 * Every §3.1 business object carries `organization_id`, and the only question
 * authorization ever asks about it is "is this user a member of that
 * organization". `BriefCatalog::visibleTo()` is the query form of the same
 * rule (`docs/architecture/pwa.md` §2); this is the per-row form every policy
 * delegates to.
 *
 * **There is no admin bypass.** `roleIn()` exists so a future policy can
 * require the owner role for a *narrower* decision; no method here widens a
 * decision on the strength of a role, and `PolicySurfaceArchTest` fails if a
 * policy ever grows a `before()` hook or the Gate grows a global one — the two
 * mechanisms by which a role could start answering "which rules apply" instead
 * of "which organizations are visible".
 *
 * Membership rows soft-delete, so `Membership::query()` already excludes a
 * revoked membership: revoking takes effect on the next check, with no cache
 * to invalidate.
 */
final class Tenancy
{
    /**
     * The organizations the user belongs to. The list form, for a query that
     * needs `whereIn` rather than a per-row decision.
     *
     * @return list<string>
     */
    public static function organizationIdsFor(User $user): array
    {
        return Membership::query()
            ->where('user_id', $user->id)
            ->pluck('organization_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }

    /**
     * Fail closed on a missing tenant: a row with no `organization_id` (or an
     * unsaved object that never got one) belongs to nobody and is readable by
     * nobody. The alternative — treating null as "global" — is exactly the
     * IDOR shape T-73 describes.
     */
    public static function isMember(User $user, ?string $organizationId): bool
    {
        if ($organizationId === null || $organizationId === '') {
            return false;
        }

        return Membership::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $organizationId)
            ->exists();
    }

    /**
     * The user's role in one organization, or null when there is no membership.
     * Never used to widen a decision; see the class docblock.
     */
    public static function roleIn(User $user, string $organizationId): ?MembershipRole
    {
        $membership = Membership::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $organizationId)
            ->first();

        return $membership?->role;
    }
}
