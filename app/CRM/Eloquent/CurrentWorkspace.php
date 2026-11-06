<?php

declare(strict_types=1);

namespace App\CRM\Eloquent;

use App\CRM\WorkspaceScope;
use App\Models\User;
use App\Security\Authorization\Tenancy;
use Illuminate\Contracts\Auth\Guard;

/**
 * The `WorkspaceScope` of the signed-in session.
 *
 * `scope()` returns **null** rather than aborting when the session has no
 * membership, and that is the whole reason this class exists rather than a
 * line inside each screen. `docs/architecture/pwa.md` §2 is explicit: *"There
 * is no admin bypass; a user without a membership sees the empty states."* A
 * page ability answers "is this a signed-in user of this install"; which rows
 * they may see is the row's decision, and "none" is a 200 with nothing in it,
 * not a 403. A screen therefore asks for a scope, gets null, and renders its
 * empty state — the same page a member with no customers yet sees.
 *
 * The scope is built from the **session**, never from the request: the
 * organization is read from the user's memberships, so a query string naming
 * another workspace cannot name one (ipc-contract §11.4 applied to the PWA,
 * and `IdorTest` asserts the negative case).
 *
 * v1 installs one workspace, so the first membership by organization id is the
 * workspace. Sorting rather than taking whatever the database returned keeps it
 * deterministic on both ADR-002 D1 profiles. A workspace switcher for a session
 * with several memberships is an admin surface and belongs to S2-19.
 */
final class CurrentWorkspace
{
    public function __construct(private readonly Guard $auth) {}

    public function scope(): ?WorkspaceScope
    {
        $user = $this->auth->user();

        if (! $user instanceof User) {
            return null;
        }

        $organizationIds = Tenancy::organizationIdsFor($user);

        if ($organizationIds === []) {
            return null;
        }

        sort($organizationIds);

        return new WorkspaceScope($organizationIds[0], (string) $user->id);
    }

    /**
     * The signed-in user. A screen behind the `auth` middleware always has one;
     * a 403 here means the middleware stack was changed, not that the person
     * lacks a membership.
     */
    public function user(): User
    {
        $user = $this->auth->user();

        if (! $user instanceof User) {
            abort(403);
        }

        return $user;
    }
}
