<?php

declare(strict_types=1);

namespace App\Security\Authorization;

use App\Models\User;
use App\Security\Audit\AuditLog;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;

/**
 * Server-side session revocation (§11, threat model T-70). The database
 * `sessions` table (ADR-006 D3: SESSION_DRIVER=database on both origins) is
 * the source of truth: deleting a user's rows ends every browser session
 * on the next request, whatever cookie the browser still holds, and
 * rotating `remember_token` kills any remember-me cookie as well. E2-3
 * builds device management on this primitive rather than replacing it.
 */
final class SessionRevoker
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly AuditLog $audit,
    ) {}

    /** @return int number of sessions revoked */
    public function revokeAll(User $user, string $actor, ?string $exceptSessionId = null): int
    {
        $query = $this->db->connection(config('session.connection'))
            ->table((string) config('session.table', 'sessions'))
            ->where('user_id', $user->id);

        if ($exceptSessionId !== null) {
            $query->where('id', '!=', $exceptSessionId);
        }

        $revoked = $query->delete();

        $user->setRememberToken(Str::random(60));
        $user->save();

        $this->audit->record('auth.sessions_revoked', $actor, AuditLog::OUTCOME_OK, 'user', $user->id, [
            'sessions' => $revoked,
            'kept_current' => $exceptSessionId !== null,
        ]);

        return $revoked;
    }

    public function activeSessionCount(User $user): int
    {
        return $this->db->connection(config('session.connection'))
            ->table((string) config('session.table', 'sessions'))
            ->where('user_id', $user->id)
            ->count();
    }
}
