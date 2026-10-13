<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Security\Authorization\Tenancy;
use Illuminate\Database\Eloquent\Model;

/**
 * The rule every §3.1 business object shares: a row is reachable by the members
 * of the organization whose `organization_id` it carries, and by nobody else
 * (blueprint §11, threat model T-73, §5.6 acceptance criterion 4).
 *
 * Each table gets its own subclass so Laravel's policy discovery finds it and
 * so a table with a narrower rule has somewhere to say so. Two narrowings are
 * expressed by constants rather than by overriding methods, because they are
 * properties of the table:
 *
 * - `APPEND_ONLY` — the row is evidence (`ai_runs`, `tool_runs`,
 *   `audit_events`, `action_approvals`). Nobody updates or deletes it through
 *   a policy; only the retention job removes it, and the retention job is not
 *   a user.
 * - `IMMUTABLE_AFTER_FREEZE` — `action_proposals`: the model itself refuses a
 *   write to a frozen column (`FrozenProposalException`), and the policy
 *   refuses the user-facing `update` so the refusal happens before the write
 *   rather than inside it.
 *
 * **No admin bypass.** There is no `before()` hook here and none in any
 * subclass: a role widens *which organizations are visible* — by adding a
 * membership — never *which rules apply*. `PolicySurfaceArchTest` fails if a
 * `before()` appears on a policy or on the Gate, because that is the one
 * mechanism by which an "admin" could start answering true for rows outside
 * their memberships.
 */
abstract class BusinessObjectPolicy
{
    /** Evidence tables: readable, never updated or deleted through a policy. */
    protected const bool APPEND_ONLY = false;

    /** `action_proposals`: frozen content is not updatable, only its status moves. */
    protected const bool IMMUTABLE_AFTER_FREEZE = false;

    /**
     * Listing is per organization, not global: a user with at least one
     * membership may ask for *their* rows. Which rows that is, is the query's
     * job (`BriefCatalog::visibleTo()` is the pattern); this answers whether
     * the question may be asked at all.
     */
    public function viewAny(User $user): bool
    {
        return Tenancy::organizationIdsFor($user) !== [];
    }

    public function view(User $user, Model $object): bool
    {
        return $this->belongsToUsersOrganization($user, $object);
    }

    /**
     * Creating takes the organization from the row being built, so a `create`
     * check is "may this user write into that organization" — and a row with
     * no organization is refused, never treated as global.
     */
    public function create(User $user, ?Model $object = null): bool
    {
        if ($object === null) {
            return Tenancy::organizationIdsFor($user) !== [];
        }

        return $this->belongsToUsersOrganization($user, $object);
    }

    public function update(User $user, Model $object): bool
    {
        if (static::APPEND_ONLY) {
            return false;
        }

        if (static::IMMUTABLE_AFTER_FREEZE && $object->getAttribute('frozen_at') !== null) {
            return false;
        }

        return $this->belongsToUsersOrganization($user, $object);
    }

    public function delete(User $user, Model $object): bool
    {
        if (static::APPEND_ONLY) {
            return false;
        }

        return $this->belongsToUsersOrganization($user, $object);
    }

    public function restore(User $user, Model $object): bool
    {
        if (static::APPEND_ONLY) {
            return false;
        }

        return $this->belongsToUsersOrganization($user, $object);
    }

    /**
     * A hard delete destroys the retention evidence the row carries (§3.1
     * `retention_policy`, `retain_until`), so it is never a user's decision on
     * any table. Erasure happens through the retention job, which answers to
     * `data_retention_rules` rather than to a policy.
     */
    public function forceDelete(User $user, Model $object): bool
    {
        return false;
    }

    /**
     * The tenant decision, in one place, reading the row rather than the
     * request: an id in a request body can name a row but can never name the
     * organization that row belongs to (ipc-contract §11.4 applied to the PWA).
     */
    final protected function belongsToUsersOrganization(User $user, Model $object): bool
    {
        return Tenancy::isMember($user, $this->organizationIdOf($object));
    }

    /**
     * Which organization a row belongs to. Every §3.1 table answers with its
     * `organization_id` column; `organizations` itself answers with its own
     * key, which is the only reason this is overridable.
     */
    protected function organizationIdOf(Model $object): ?string
    {
        $organizationId = $object->getAttribute('organization_id');

        return is_string($organizationId) && $organizationId !== '' ? $organizationId : null;
    }
}
