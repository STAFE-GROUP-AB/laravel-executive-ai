<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Membership;
use App\Models\User;
use App\Security\Authorization\CreateOwner;
use App\Security\Authorization\Otp\OtpCode;
use App\Security\Authorization\Otp\OtpMail;
use App\Security\Authorization\OwnerDetails;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * Owner-account fixtures for the S1-02 tests. Everything runs inside
 * DatabaseTransactions; `clear()` only removes rows the enclosing
 * transaction will roll back anyway, so a leftover owner from another
 * test can never make "no owner exists" assertions flaky.
 */
final class OwnerAccount
{
    public const string EMAIL = 'owner@example.test';

    public static function clear(): void
    {
        OtpCode::query()->delete();
        Membership::query()->forceDelete();
    }

    public static function create(string $email = self::EMAIL, string $name = 'Ada Owner'): User
    {
        $details = new OwnerDetails($name, $email, 'Example Organization', 'Europe/Stockholm', 'en');

        return app(CreateOwner::class)->create($details, 'job:OwnerAccountFixture')->user;
    }

    /** The plaintext code carried by the most recent faked OtpMail. */
    public static function lastMailedCode(): string
    {
        $mail = Mail::sent(OtpMail::class)->last();

        if (! $mail instanceof OtpMail) {
            throw new RuntimeException('No OtpMail was sent through Mail::fake().');
        }

        return $mail->code;
    }
}
