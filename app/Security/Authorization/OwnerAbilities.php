<?php

declare(strict_types=1);

namespace App\Security\Authorization;

use App\Models\Brief;
use App\Models\Membership;
use App\Models\MembershipRole;
use App\Models\User;
use Illuminate\Contracts\Auth\Access\Gate;

/**
 * The named abilities of the IPC contract's §6 operation table, defined once
 * so the PWA and the IPC run the same gate (§11.1). Every ability requires
 * the owner membership; `viewBrief` additionally delegates to BriefPolicy so
 * organisation scoping is identical to the PWA's brief pages. The last
 * argument of every check is the caller context (`['via' => 'ipc', ...]`),
 * which a future policy may use to deny the IPC path explicitly (§2).
 */
final class OwnerAbilities
{
    public const string VIEW_BRIEF = 'viewBrief';

    public const string VIEW_APPROVALS = 'viewApprovals';

    public const string PAUSE_AI = 'pauseAi';

    public const string RESUME_AI = 'resumeAi';

    public const string COMPLETE_OAUTH = 'completeOAuth';

    public const string VIEW_REMOTE_ACCESS = 'viewRemoteAccess';

    public const string ENABLE_REMOTE_ACCESS = 'enableRemoteAccess';

    public const string DISABLE_REMOTE_ACCESS = 'disableRemoteAccess';

    /** @var list<string> */
    public const array OWNER_ONLY = [
        self::VIEW_APPROVALS,
        self::PAUSE_AI,
        self::RESUME_AI,
        self::COMPLETE_OAUTH,
        self::VIEW_REMOTE_ACCESS,
        self::ENABLE_REMOTE_ACCESS,
        self::DISABLE_REMOTE_ACCESS,
    ];

    public static function define(Gate $gate): void
    {
        $gate->define(
            self::VIEW_BRIEF,
            static fn (User $user, Brief $brief, array $context = []): bool => self::isOwner($user) && $gate->forUser($user)->allows('view', $brief),
        );

        foreach (self::OWNER_ONLY as $ability) {
            $gate->define($ability, static fn (User $user, array $context = []): bool => self::isOwner($user));
        }
    }

    public static function isOwner(User $user): bool
    {
        return Membership::query()
            ->where('user_id', $user->id)
            ->where('role', MembershipRole::Owner)
            ->exists();
    }
}
