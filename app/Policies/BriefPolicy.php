<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Brief;
use App\Models\Membership;
use App\Models\User;

/**
 * A brief is visible to the members of the organization it belongs to and to
 * nobody else (§11 "Laravel Policies on every read"). Sprint 1 has one
 * organization per install; the check is still per brief so a second
 * organization can never read across (§5.6 acceptance criterion 4).
 */
final class BriefPolicy
{
    public function view(User $user, Brief $brief): bool
    {
        return Membership::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $brief->organization_id)
            ->exists();
    }
}
