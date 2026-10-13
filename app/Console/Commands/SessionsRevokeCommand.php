<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Security\Authorization\SessionRevoker;
use Illuminate\Console\Command;

/**
 * `lea:sessions:revoke {user}` ends every session of a user server-side;
 * the user is signed out on their next request. `{user}` is a ULID or an
 * email address. The Mac app's "sign out everywhere" calls this through the
 * Supervisor; the per-device variant is the PWA's Devices page (S2-05,
 * `App\Security\Authorization\Devices\DeviceRegistry`).
 */
class SessionsRevokeCommand extends Command
{
    protected $signature = 'lea:sessions:revoke
        {user : User ULID or email address}
        {--json : Print the result as JSON}';

    protected $description = 'Revoke every server-side session of a user (§11 session revocation)';

    public function handle(SessionRevoker $revoker): int
    {
        $identifier = (string) $this->argument('user');

        $user = User::query()
            ->where('id', $identifier)
            ->orWhere('email', mb_strtolower(trim($identifier)))
            ->first();

        if ($user === null) {
            $this->error('No user matches that ULID or email address.');

            return self::INVALID;
        }

        $this->comment("Revoking sessions for user `{$user->id}`...");

        $revoked = $revoker->revokeAll($user, 'job:SessionsRevokeCommand');

        if ($this->option('json')) {
            $this->line(json_encode(['status' => 'revoked', 'user_id' => $user->id, 'sessions' => $revoked], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->comment("Revoked {$revoked} session(s); the user is signed out on their next request.");

        return self::SUCCESS;
    }
}
