<?php

declare(strict_types=1);

namespace App\Security\Authorization\Reauthentication;

use App\Security\Authorization\Devices\LoginMethod;
use Carbon\CarbonImmutable;

/**
 * The outcome `Reauthenticator::assert()` returns once: which action, which
 * nonce, how presence was proven and until when the proof was valid. Carries
 * nothing a caller could replay.
 */
final readonly class ReauthProof
{
    public function __construct(
        public CriticalAction $action,
        public string $nonce,
        public LoginMethod $method,
        public CarbonImmutable $verifiedAt,
        public CarbonImmutable $expiresAt,
    ) {}
}
