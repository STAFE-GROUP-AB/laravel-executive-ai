<?php

declare(strict_types=1);

namespace App\Security\Authorization\Otp;

use App\Models\Membership;
use App\Models\MembershipRole;
use Illuminate\Contracts\Foundation\Application;

/**
 * Local one-click login (the owner's rule): when APP_ENV is `local` and
 * OTP_AUTOFILL is true, the login page pre-fills the owner's address and,
 * after issuing a code, verifies it immediately instead of asking for the
 * mail. Both conditions are checked on every call; the environment check is
 * on the application, not on config, so a stray `OTP_AUTOFILL=true` in a
 * production .env changes nothing (OtpAutofillTest proves it).
 */
final class OtpAutofill
{
    public function __construct(private readonly Application $app) {}

    public function isActive(): bool
    {
        if (! $this->app->environment('local')) {
            return false;
        }

        return (bool) config('lea.auth.otp.autofill');
    }

    public function ownerEmail(): ?string
    {
        $owner = Membership::query()
            ->where('role', MembershipRole::Owner->value)
            ->with('user')
            ->orderBy('created_at')
            ->first();

        return $owner?->user?->email;
    }
}
