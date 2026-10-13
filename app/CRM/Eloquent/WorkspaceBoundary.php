<?php

declare(strict_types=1);

namespace App\CRM\Eloquent;

use App\CRM\Exceptions\WorkspaceAccessDeniedException;
use App\CRM\WorkspaceScope;
use App\Models\User;
use App\Security\Authorization\Gates\Abilities;
use App\Security\Authorization\Gates\AuthorizationAuditor;
use Illuminate\Contracts\Auth\Access\Gate;

/**
 * The one place a `WorkspaceScope` becomes permission to read an organization.
 *
 * Every CRM query in this namespace goes through `organizationFor()` before a
 * builder exists at all, so the tenant decision is a **precondition of having
 * a query**, not a `where` clause a caller is trusted to remember. That is the
 * difference S2-06 is built on (`docs/architecture/authorization.md` §1): the
 * boundary is structural, and `WorkspaceRepositoryContractTest` proves it by
 * handing a repository another organization's id and getting a refusal with no
 * caller-side filtering anywhere.
 *
 * Three properties, each deliberate:
 *
 * 1. **The question is the shared one.** It is put to the
 *    `accessOrganization` gate, which is `Tenancy::isMember()` — the same
 *    check every policy delegates to. There is no second tenant rule here to
 *    keep in step with the policies, and no `before()` hook to bypass it.
 * 2. **A refusal is the truth, not an empty page.** The §11 cross-account
 *    tests must be able to tell "nothing there" from "refused"
 *    (`WorkspaceAccessDeniedException`'s own docblock), so a scope naming an
 *    organization the actor is not a member of throws rather than returning
 *    zero rows.
 * 3. **A missing actor is a refusal that still leaves a record.** A scope
 *    whose `actorUserId` no longer exists never reaches the Gate, so the
 *    `after` hook cannot audit it; `AuthorizationAuditor::refuse()` records it
 *    with `reason: actor_missing`, exactly as `AuthorizesQueuedWork` does for
 *    a job whose dispatcher was deleted (T-46).
 *
 * The scope itself comes from the authenticated session or a job's stored
 * context and never from model output or request content — see
 * `WorkspaceScope`.
 */
final class WorkspaceBoundary
{
    public function __construct(
        private readonly Gate $gate,
        private readonly AuthorizationAuditor $auditor,
    ) {}

    /**
     * The organization the scope may read.
     *
     * @throws WorkspaceAccessDeniedException the actor has no membership in it, or no longer exists
     */
    public function organizationFor(WorkspaceScope $scope): string
    {
        $actor = User::query()->find($scope->actorUserId);

        if (! $actor instanceof User) {
            $this->auditor->refuse("user:{$scope->actorUserId}", Abilities::ACCESS_ORGANIZATION, 'actor_missing');

            throw new WorkspaceAccessDeniedException($scope->organizationId, $scope->actorUserId);
        }

        if ($this->gate->forUser($actor)->denies(Abilities::ACCESS_ORGANIZATION, [$scope->organizationId])) {
            throw new WorkspaceAccessDeniedException($scope->organizationId, $scope->actorUserId);
        }

        return $scope->organizationId;
    }

    /** Whether the scope is readable, without the exception — for a screen that renders an empty state. */
    public function allows(WorkspaceScope $scope): bool
    {
        try {
            $this->organizationFor($scope);

            return true;
        } catch (WorkspaceAccessDeniedException) {
            return false;
        }
    }
}
