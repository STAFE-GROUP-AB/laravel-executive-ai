<?php

declare(strict_types=1);

namespace App\Security\Authorization\Gates;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Database\Eloquent\Model;

/**
 * A queued job's authorization seam (blueprint §11; threat model T-73 names a
 * queued job as one of the five paths cross-account access can be attempted on).
 *
 * A job has no session, so the principal cannot be "whoever is logged in". It
 * is the user id the job was *dispatched with*, carried in the payload — and
 * that is the point: a job runs as the person who asked for it, with their
 * memberships, and a job dispatched by a member of organization A refuses
 * organization B's rows exactly as the PWA would. There is no privileged job
 * identity to escalate to.
 *
 * A job that touches no business object needs none of this: `QueueSelftestJob`
 * records a heartbeat and reaches no tenant row. `PolicySurfaceArchTest`
 * decides which is which by *reading the job*, not from a list — a job whose
 * source references a tenant model or the query builder must authorize.
 */
trait AuthorizesQueuedWork
{
    /**
     * @param  string  $actorUserId  the `users.id` the job was dispatched with
     * @param  Model|string|null  $subject  the row, or the class for a `create`/`viewAny` ability
     *
     * @throws AuthorizationException the actor is gone, or the row is another organization's
     */
    protected function authorizeJob(string $actorUserId, string $ability, Model|string|null $subject = null): void
    {
        $gate = app(Gate::class);
        $surfaces = app(SurfaceContext::class);
        $auditor = app(AuthorizationAuditor::class);

        $surfaces->within(Surface::Job, static function () use ($gate, $auditor, $actorUserId, $ability, $subject): void {
            $actor = User::query()->find($actorUserId);

            if (! $actor instanceof User) {
                $auditor->refuse("user:{$actorUserId}", $ability, 'actor_missing', $subject);

                throw new AuthorizationException("The job's actor no longer exists.");
            }

            $gate->forUser($actor)->authorize($ability, $subject === null ? [] : [$subject]);
        });
    }
}
