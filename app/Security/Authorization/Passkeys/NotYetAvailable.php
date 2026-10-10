<?php

declare(strict_types=1);

namespace App\Security\Authorization\Passkeys;

use App\Models\User;

/**
 * The Sprint 1 passkey stub. Reports itself honestly ("Passkeys arrive in
 * Sprint 2 (E2-3)") and refuses every ceremony with an exception, so nothing
 * can mistake it for a working authenticator. Removed by E2-3 (issue #69).
 */
final class NotYetAvailable implements PasskeyAuthenticator
{
    public const string MESSAGE = 'Passkeys arrive in Sprint 2 (E2-3)';

    public function __construct(private readonly string $trackingUrl) {}

    public function isAvailable(): bool
    {
        return false;
    }

    public function status(): PasskeyStatus
    {
        return new PasskeyStatus(available: false, message: self::MESSAGE, trackingUrl: $this->trackingUrl);
    }

    public function registrationOptions(User $user): array
    {
        throw PasskeysUnavailableException::notYetAvailable($this->trackingUrl);
    }

    public function completeRegistration(User $user, array $credential): void
    {
        throw PasskeysUnavailableException::notYetAvailable($this->trackingUrl);
    }

    public function authenticationOptions(string $email): array
    {
        throw PasskeysUnavailableException::notYetAvailable($this->trackingUrl);
    }

    public function completeAuthentication(array $credential): ?User
    {
        throw PasskeysUnavailableException::notYetAvailable($this->trackingUrl);
    }
}
