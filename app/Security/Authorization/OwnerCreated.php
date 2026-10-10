<?php

declare(strict_types=1);

namespace App\Security\Authorization;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Support\Install\InstallState;

final readonly class OwnerCreated
{
    public function __construct(
        public Organization $organization,
        public User $user,
        public Membership $membership,
        public InstallState $installState,
    ) {}

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'status' => 'created',
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'membership_id' => $this->membership->id,
            'email' => $this->user->email,
            'install_state' => $this->installState->value,
        ];
    }
}
