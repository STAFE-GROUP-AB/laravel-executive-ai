<?php

declare(strict_types=1);

namespace App\Security\Authorization;

use App\Models\Membership;
use App\Models\MembershipRole;
use App\Models\Organization;
use App\Models\User;
use App\Security\Audit\AuditLog;
use App\Support\Data\Provenance;
use App\Support\Data\ProvenanceOrigin;
use App\Support\Install\InstallState;
use App\Support\Install\InstallStateStore;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The §12.1 CREATE_OWNER step: organization, user and owner membership in
 * one transaction, behind a process-wide lock so two installer runs cannot
 * race past the owner-exists check. Refuses — loudly, with its own
 * exception — when an owner already exists (T-72). Moves the install marker
 * from not_installed to installing and leaves it there: HEALTH_CHECK, not
 * this step, decides core_ready.
 */
final class CreateOwner
{
    public function __construct(
        private readonly InstallStateStore $installState,
        private readonly AuditLog $audit,
        private readonly DatabaseManager $db,
    ) {}

    /**
     * `$context` is merged into whichever audit record this call writes, so
     * a door that carries request context — the IPC operation's
     * `{via: ipc, client, session_id, request_id}` (IPC contract §6.9, §10)
     * — records it without a second audit writer.
     *
     * @param  array<string, mixed>  $context
     *
     * @throws OwnerAlreadyExistsException
     */
    public function create(OwnerDetails $details, string $actor = 'job:CreateOwner', array $context = []): OwnerCreated
    {
        return Cache::lock('lea:create-owner', 10)->block(5, function () use ($details, $actor, $context): OwnerCreated {
            $existing = $this->existingOwner();

            if ($existing !== null) {
                $this->refuse($existing, $actor, $context);
            }

            return $this->db->transaction(fn (): OwnerCreated => $this->createWithinTransaction($details, $actor, $context));
        });
    }

    public function ownerExists(): bool
    {
        return $this->existingOwner() !== null;
    }

    public function existingOwner(): ?Membership
    {
        return Membership::query()->where('role', MembershipRole::Owner->value)->orderBy('created_at')->first();
    }

    /**
     * Recorded outside the creation transaction so the refusal survives the
     * exception; a second check inside the transaction covers the race.
     *
     * @param  array<string, mixed>  $context
     */
    private function refuse(Membership $existing, string $actor, array $context = []): never
    {
        $this->audit->record('auth.owner_create_refused', $actor, AuditLog::OUTCOME_REFUSED, 'membership', $existing->id, [
            ...$context,
            'reason' => 'owner_exists',
        ], $existing->organization_id);

        throw new OwnerAlreadyExistsException($existing->user_id);
    }

    /** @param array<string, mixed> $context */
    private function createWithinTransaction(OwnerDetails $details, string $actor, array $context = []): OwnerCreated
    {
        $existing = $this->existingOwner();

        if ($existing !== null) {
            throw new OwnerAlreadyExistsException($existing->user_id);
        }

        $organization = Organization::query()->create([
            'name' => $details->organizationName,
            'slug' => $this->uniqueSlug($details->organizationName),
            'timezone' => $details->timezone,
            'language' => $details->language,
            'sensitivity_class' => 'internal',
            'provenance' => Provenance::job('CreateOwner', ProvenanceOrigin::Manual),
        ]);

        $user = User::query()->create([
            'name' => $details->name,
            'email' => $details->email,
        ]);

        $membership = Membership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => MembershipRole::Owner,
            'sensitivity_class' => 'personal',
            'provenance' => Provenance::user($user->id),
        ]);

        $state = $this->installState->current();

        if ($state === InstallState::NotInstalled) {
            $state = $this->installState->transition(InstallState::Installing, 'CREATE_OWNER')->state;
        }

        $this->audit->record('auth.owner_created', $actor, AuditLog::OUTCOME_OK, 'user', $user->id, [
            ...$context,
            'organization_id' => $organization->id,
            'membership_id' => $membership->id,
            'timezone' => $details->timezone,
            'language' => $details->language,
        ], $organization->id);

        return new OwnerCreated($organization, $user, $membership, $state);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);

        if ($base === '') {
            $base = 'workspace';
        }

        $slug = $base;
        $suffix = 2;

        while (Organization::query()->withTrashed()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
