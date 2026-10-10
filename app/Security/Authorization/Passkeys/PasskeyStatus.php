<?php

declare(strict_types=1);

namespace App\Security\Authorization\Passkeys;

/**
 * What the settings row and the login page show about passkeys.
 */
final readonly class PasskeyStatus
{
    public function __construct(
        public bool $available,
        public string $message,
        public ?string $trackingUrl = null,
    ) {}
}
