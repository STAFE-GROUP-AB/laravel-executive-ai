<?php

declare(strict_types=1);

namespace App\Security\Authorization\Otp;

/**
 * Outcome of one verification attempt (docs/architecture/auth.md §3, the OTP
 * state machine). Only `Verified` signs the user in.
 */
enum OtpVerification: string
{
    case Verified = 'verified';
    case Invalid = 'invalid';
    case Expired = 'expired';
    case LockedOut = 'locked_out';
    case NoActiveCode = 'no_active_code';

    public function succeeded(): bool
    {
        return $this === self::Verified;
    }

    public function message(): string
    {
        return match ($this) {
            self::Verified => 'Signed in.',
            self::Invalid => 'That code is not valid.',
            self::Expired => 'That code has expired. Request a new one.',
            self::LockedOut => 'Too many incorrect codes. Request a new one.',
            self::NoActiveCode => 'No code is active for this address. Request a new one.',
        };
    }
}
