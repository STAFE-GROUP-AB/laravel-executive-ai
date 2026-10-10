<?php

declare(strict_types=1);

namespace App\Security\Authorization\Otp;

use App\Models\User;
use App\Security\Audit\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Cache;
use SensitiveParameter;

/**
 * Verifies a submitted code against the user's latest unconsumed one
 * (docs/architecture/auth.md §3). Behind a per-user lock and inside a
 * transaction so two concurrent submissions cannot both consume the code
 * or both count as the fifth attempt. Every outcome but `Verified` is an
 * audit event; the code itself is never part of any event.
 */
final class OtpVerifier
{
    public function __construct(
        private readonly OtpHasher $hasher,
        private readonly AuditLog $audit,
        private readonly DatabaseManager $db,
    ) {}

    public function verify(User $user, #[SensitiveParameter] string $code, ?string $ipAddress): OtpVerification
    {
        return Cache::lock("lea:otp:verify:{$user->id}", 10)->block(5, function () use ($user, $code, $ipAddress): OtpVerification {
            return $this->db->transaction(fn (): OtpVerification => $this->attempt($user, $code, $ipAddress));
        });
    }

    public function maxAttempts(): int
    {
        return max(1, (int) config('lea.auth.otp.max_attempts'));
    }

    private function attempt(User $user, #[SensitiveParameter] string $code, ?string $ipAddress): OtpVerification
    {
        $now = CarbonImmutable::now('UTC');
        $actor = "user:{$user->id}";
        $context = ['ip_address' => $ipAddress];

        $record = OtpCode::query()
            ->where('user_id', $user->id)
            ->whereNull('consumed_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        if ($record === null) {
            $this->audit->record('auth.otp_failed', $actor, AuditLog::OUTCOME_REFUSED, 'user', $user->id, [...$context, 'reason' => 'no_active_code']);

            return OtpVerification::NoActiveCode;
        }

        if ($record->isLockedOut($this->maxAttempts())) {
            $this->audit->record('auth.otp_locked_out', $actor, AuditLog::OUTCOME_REFUSED, 'otp_code', $record->id, [...$context, 'attempts' => $record->attempts]);

            return OtpVerification::LockedOut;
        }

        if ($record->isExpired($now)) {
            $this->audit->record('auth.otp_failed', $actor, AuditLog::OUTCOME_REFUSED, 'otp_code', $record->id, [...$context, 'reason' => 'expired']);

            return OtpVerification::Expired;
        }

        if (! $this->matches($code, $record)) {
            $record->attempts++;
            $record->save();

            if ($record->isLockedOut($this->maxAttempts())) {
                $this->audit->record('auth.otp_locked_out', $actor, AuditLog::OUTCOME_REFUSED, 'otp_code', $record->id, [...$context, 'attempts' => $record->attempts]);

                return OtpVerification::LockedOut;
            }

            $this->audit->record('auth.otp_failed', $actor, AuditLog::OUTCOME_REFUSED, 'otp_code', $record->id, [...$context, 'reason' => 'mismatch', 'attempts' => $record->attempts]);

            return OtpVerification::Invalid;
        }

        $record->consumed_at = $now;
        $record->save();

        $user->forceFill([
            'last_login_at' => $now,
            'login_count' => ((int) $user->getAttribute('login_count')) + 1,
            'email_verified_at' => $user->getAttribute('email_verified_at') ?? $now,
        ])->save();

        $this->audit->record('auth.otp_verified', $actor, AuditLog::OUTCOME_OK, 'otp_code', $record->id, $context);

        return OtpVerification::Verified;
    }

    private function matches(#[SensitiveParameter] string $code, OtpCode $record): bool
    {
        $digits = preg_replace('/\D+/', '', $code) ?? '';

        if (strlen($digits) !== (int) config('lea.auth.otp.length')) {
            return false;
        }

        return $this->hasher->matches($digits, $record->code_hash);
    }
}
