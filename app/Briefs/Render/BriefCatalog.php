<?php

declare(strict_types=1);

namespace App\Briefs\Render;

use App\Models\Brief;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * List-side counterpart of App\Policies\BriefPolicy::view: the versions a
 * user may list are those in the organizations the user is a member of.
 * Reading one brief goes through the policy (Gate::authorize('view')); this
 * class only answers "which ones" and "which row does this id mean".
 */
final class BriefCatalog
{
    /** @return Builder<Brief> */
    public function visibleTo(User $user): Builder
    {
        $organizationIds = Membership::query()
            ->select('organization_id')
            ->where('user_id', $user->id);

        return Brief::query()->whereIn('organization_id', $organizationIds);
    }

    public function latestFor(User $user): ?Brief
    {
        return $this->visibleTo($user)->latestGenerated()->first();
    }

    /** @return Collection<int, Brief> */
    public function versionsFor(User $user): Collection
    {
        return $this->visibleTo($user)->latestGenerated()->get();
    }

    /**
     * `/briefs/{brief}` accepts either a version row id (that exact version)
     * or a document id (its newest version), so the mail's "Open the full
     * brief" link and the menu bar's `latest_brief.id` — both document ids —
     * resolve, while the version history can still link to one version.
     */
    public function resolve(string $key): ?Brief
    {
        return Brief::query()
            ->where(fn (Builder $query) => $query->whereKey($key)->orWhere('document_id', $key))
            ->latestGenerated()
            ->first();
    }
}
