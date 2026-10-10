<?php

declare(strict_types=1);

namespace App\Security\Authorization\Passkeys;

use RuntimeException;

final class PasskeysUnavailableException extends RuntimeException
{
    public static function notYetAvailable(string $trackingUrl): self
    {
        return new self("Passkeys arrive in Sprint 2 (E2-3): {$trackingUrl}");
    }
}
