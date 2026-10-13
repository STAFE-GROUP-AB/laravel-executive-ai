<?php

declare(strict_types=1);

namespace App\Security\Authorization\Passkeys;

/**
 * What the login page and the app shell show about passkeys on the origin
 * the request arrived on. `rpId` is the relying-party id the ceremonies bind
 * to there; `trackingUrl` is kept for callers that linked the Sprint 1 stub
 * and is always null now that passkeys exist.
 */
final readonly class PasskeyStatus
{
    public function __construct(
        public bool $available,
        public string $message,
        public ?string $rpId = null,
        public ?string $trackingUrl = null,
    ) {}
}
