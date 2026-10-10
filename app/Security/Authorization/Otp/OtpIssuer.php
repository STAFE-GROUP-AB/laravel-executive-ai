<?php

declare(strict_types=1);

namespace App\Security\Authorization\Otp;

use App\Models\User;
use App\Security\Audit\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Facades\Cache;

/**
 * Issues one-time login codes (docs/architecture/auth.md §3).
 *
 * `issue()` is what the login page calls. It runs behind a cache lock per
 * user and, when a code issued less than `cooldown_seconds` ago is still
 * valid and not locked out, returns that one without sending anything —
 * so however many times the user clicks or presses Enter, at most one mail
 * leaves per cooldown (the owner's double-send rule). Otherwise it mints a
 * fresh code, expires the previous active ones and sends the mail
 * synchronously: the code must never ride in a queue payload.
 *
 * `mint()` never sends. It exists for the health login self-test, which
 * mints and verifies a code inside a transaction it rolls back, and it is
 * what `issue()` uses internally.
 */
final class OtpIssuer
{
    public function __construct(
        private readonly OtpHasher $hasher,
        private readonly Mailer $mailer,
        private readonly AuditLog $audit,
    ) {}

    public function issue(User $user, ?string $ipAddress): IssuedOtp
    {
        return Cache::lock("lea:otp:issue:{$user->id}", 10)->block(5, function () use ($user, $ipAddress): IssuedOtp {
            $reusable = $this->reusable($user);

            if ($reusable !== null) {
                $this->audit->record('auth.otp_reused', "user:{$user->id}", AuditLog::OUTCOME_OK, 'otp_code', $reusable->id, [
                    'ip_address' => $ipAddress,
                    'expires_at' => $reusable->expires_at->toRfc3339String(),
                ]);

                return new IssuedOtp($reusable, reused: true, delivered: false);
            }

            $minted = $this->mint($user, $ipAddress);

            $this->mailer->to($user)->send(new OtpMail($user->name, $minted->code(), $this->ttlMinutes()));

            $this->audit->record('auth.otp_issued', "user:{$user->id}", AuditLog::OUTCOME_OK, 'otp_code', $minted->record->id, [
                'ip_address' => $ipAddress,
                'expires_at' => $minted->record->expires_at->toRfc3339String(),
            ]);

            return new IssuedOtp($minted->record, reused: false, delivered: true, code: $minted->code());
        });
    }

    /**
     * Generate and store a fresh code, expiring every active one before it.
     * Sends nothing and writes no audit event; callers do both.
     */
    public function mint(User $user, ?string $ipAddress): MintedOtp
    {
        $now = CarbonImmutable::now('UTC');

        OtpCode::query()->where('user_id', $user->id)->active($now)->update(['expires_at' => $now]);

        $code = $this->generate();

        $record = OtpCode::query()->create([
            'user_id' => $user->id,
            'code_hash' => $this->hasher->hash($code),
            'expires_at' => $now->addMinutes($this->ttlMinutes()),
            'attempts' => 0,
            'ip_address' => $ipAddress,
            'created_at' => $now,
        ]);

        return new MintedOtp($record, $code);
    }

    public function ttlMinutes(): int
    {
        return max(1, (int) config('lea.auth.otp.ttl_minutes'));
    }

    public function cooldownSeconds(): int
    {
        return max(0, (int) config('lea.auth.otp.cooldown_seconds'));
    }

    private function reusable(User $user): ?OtpCode
    {
        $now = CarbonImmutable::now('UTC');

        return OtpCode::query()
            ->where('user_id', $user->id)
            ->active($now)
            ->where('attempts', '<', (int) config('lea.auth.otp.max_attempts'))
            ->where('created_at', '>', $now->subSeconds($this->cooldownSeconds()))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    private function generate(): string
    {
        $length = max(4, (int) config('lea.auth.otp.length'));

        return str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);
    }
}
