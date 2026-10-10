<?php

declare(strict_types=1);

namespace App\Security\Authorization\Otp;

use SensitiveParameter;

/**
 * Codes are stored as HMAC-SHA256 keyed with the application key. A six-digit
 * code has too little entropy for a password hash to add anything — a stolen
 * table of bcrypt digests is brute-forced in a million guesses — whereas the
 * keyed MAC makes the stored value useless without the key, which lives in
 * the Keychain-backed environment, not the database. Comparison is
 * constant-time.
 */
final class OtpHasher
{
    public function __construct(#[SensitiveParameter] private readonly string $key) {}

    public function hash(#[SensitiveParameter] string $code): string
    {
        return hash_hmac('sha256', $code, $this->key);
    }

    public function matches(#[SensitiveParameter] string $code, string $hash): bool
    {
        return hash_equals($hash, $this->hash($code));
    }
}
