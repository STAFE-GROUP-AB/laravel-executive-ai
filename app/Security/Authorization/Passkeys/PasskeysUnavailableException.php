<?php

declare(strict_types=1);

namespace App\Security\Authorization\Passkeys;

use RuntimeException;

/**
 * Thrown when a ceremony cannot start on the current origin. The message
 * names the origin, never a challenge or a credential.
 */
final class PasskeysUnavailableException extends RuntimeException
{
    public static function forOrigin(RelyingParty $relyingParty): self
    {
        return new self("Passkeys are not available on the origin {$relyingParty->origin}: WebAuthn needs a domain name and a secure context.");
    }
}
