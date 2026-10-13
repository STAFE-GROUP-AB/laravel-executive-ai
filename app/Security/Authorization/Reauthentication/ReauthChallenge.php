<?php

declare(strict_types=1);

namespace App\Security\Authorization\Reauthentication;

use Carbon\CarbonImmutable;

/**
 * What a consumer hands to the `Reauthenticate` component: the action and the
 * nonce the proof will be bound to, and when the challenge stops being
 * answerable. The nonce is opaque and single-use.
 */
final readonly class ReauthChallenge
{
    public function __construct(
        public CriticalAction $action,
        public string $nonce,
        public CarbonImmutable $expiresAt,
    ) {}
}
