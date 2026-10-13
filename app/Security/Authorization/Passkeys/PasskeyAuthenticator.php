<?php

declare(strict_types=1);

namespace App\Security\Authorization\Passkeys;

use App\Models\User;

/**
 * Passkey / WebAuthn login (§11, docs/architecture/auth.md §7). Options and
 * credentials are the WebAuthn JSON shapes (`PublicKeyCredentialCreationOptions`,
 * `PublicKeyCredentialRequestOptions`, `PublicKeyCredential` as produced by
 * `credential.toJSON()`) as associative arrays. A passkey is an addition to
 * the emailed one-time code, never a replacement: the code stays the fallback
 * and the recovery path, and nothing here reads or writes `users.password`.
 */
interface PasskeyAuthenticator
{
    /** Whether a ceremony can run on the origin the current request arrived on. */
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
     * @throws PasskeyCeremonyFailed
     */
    public function completeRegistration(User $user, array $credential): void;

    /**
     * The address is a hint only: an unknown address yields the same shape
     * with no `allowCredentials`, so the login page never reveals whether an
     * account exists.
     *
     * @return array<string, mixed>
     *
     * @throws PasskeysUnavailableException
     */
    public function authenticationOptions(string $email): array;

    /**
     * The user whose passkey signed the challenge, or null when the assertion
     * is not acceptable for any reason (the reason is an audit event, never a
     * message that carries the payload).
     *
     * @param  array<string, mixed>  $credential
     *
     * @throws PasskeysUnavailableException
     */
    public function completeAuthentication(array $credential): ?User;
}
