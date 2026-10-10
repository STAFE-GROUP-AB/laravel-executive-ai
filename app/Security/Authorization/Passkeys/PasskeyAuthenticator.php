<?php

declare(strict_types=1);

namespace App\Security\Authorization\Passkeys;

use App\Models\User;

/**
 * Passkey / WebAuthn login (§11). Sprint 1 binds the NotYetAvailable stub
 * (S1-02 criterion 5: "a stub with a linked Sprint 2 issue — not silently
 * omitted"); E2-3 (issue #69) replaces it with a real implementation and
 * removes the stub. Options and credentials are the WebAuthn JSON shapes
 * (`PublicKeyCredentialCreationOptions`, `PublicKeyCredentialRequestOptions`,
 * `PublicKeyCredential`) as associative arrays.
 */
interface PasskeyAuthenticator
{
    public function isAvailable(): bool;

    public function status(): PasskeyStatus;

    /**
     * @return array<string, mixed>
     *
     * @throws PasskeysUnavailableException
     */
    public function registrationOptions(User $user): array;

    /**
     * @param  array<string, mixed>  $credential
     *
     * @throws PasskeysUnavailableException
     */
    public function completeRegistration(User $user, array $credential): void;

    /**
     * @return array<string, mixed>
     *
     * @throws PasskeysUnavailableException
     */
    public function authenticationOptions(string $email): array;

    /**
     * @param  array<string, mixed>  $credential
     *
     * @throws PasskeysUnavailableException
     */
    public function completeAuthentication(array $credential): ?User;
}
