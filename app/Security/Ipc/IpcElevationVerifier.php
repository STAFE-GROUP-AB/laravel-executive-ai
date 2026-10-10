<?php

declare(strict_types=1);

namespace App\Security\Ipc;

use Carbon\CarbonImmutable;

/**
 * §7.3: the one-shot elevation the Supervisor mints after device-owner
 * authentication. Verified under `K`; bound to the session, the operation
 * slug and the request nonce (which the replay cache makes single-use).
 */
final class IpcElevationVerifier
{
    public function __construct(private readonly IpcKeySource $keys) {}

    /**
     * @throws IpcException elevation_required | elevation_invalid
     * @throws IpcKeyUnavailableException
     */
    public function verify(?string $header, IpcSession $session, string $operation, string $requestNonce, ?CarbonImmutable $now = null): void
    {
        if ($header === null || $header === '') {
            throw IpcException::elevationRequired();
        }

        if (preg_match(IpcSigner::ELEVATION_PATTERN, $header, $parts) !== 1) {
            throw IpcException::elevationInvalid();
        }

        [, $presentedOperation, $nonce, $expiresAt, $presentedMac] = $parts;

        if ($presentedOperation !== $operation || ! hash_equals($requestNonce, $nonce)) {
            throw IpcException::elevationInvalid();
        }

        if ((int) $expiresAt <= ($now ?? CarbonImmutable::now('UTC'))->getTimestamp()) {
            throw IpcException::elevationInvalid();
        }

        $expectedMac = IpcSigner::elevationMac($this->keys->key(), $session->sessionId, $operation, $nonce, (int) $expiresAt);

        if (! IpcSigner::constantTimeEquals($expectedMac, $presentedMac)) {
            throw IpcException::elevationInvalid();
        }
    }
}
