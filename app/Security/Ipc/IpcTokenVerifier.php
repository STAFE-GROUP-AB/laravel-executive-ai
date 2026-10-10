<?php

declare(strict_types=1);

namespace App\Security\Ipc;

use Carbon\CarbonImmutable;

/**
 * §3.3: a token is verified by recomputing its MAC under `K` (constant-time
 * compare) and checking `expires_at > now`. Nothing is looked up; the
 * server keeps no session table. Any failure is the single reason
 * `session_invalid` — the caller never learns which part failed.
 */
final class IpcTokenVerifier
{
    public function __construct(private readonly IpcKeySource $keys) {}

    /**
     * @throws IpcException session_invalid
     * @throws IpcKeyUnavailableException
     */
    public function verify(?string $authorizationHeader, ?CarbonImmutable $now = null): IpcSession
    {
        if ($authorizationHeader === null || ! str_starts_with($authorizationHeader, 'Bearer ')) {
            throw IpcException::sessionInvalid();
        }

        $token = substr($authorizationHeader, 7);

        if (preg_match(IpcSigner::TOKEN_PATTERN, $token, $parts) !== 1) {
            throw IpcException::sessionInvalid();
        }

        $client = IpcClient::from($parts[1]);
        $sessionId = $parts[2];
        $expiresAt = (int) $parts[3];
        $presentedMac = $parts[4];

        $key = $this->keys->key();
        $expectedMac = IpcSigner::tokenMac($key, $client, $sessionId, $expiresAt);

        if (! IpcSigner::constantTimeEquals($expectedMac, $presentedMac)) {
            throw IpcException::sessionInvalid();
        }

        if ($expiresAt <= ($now ?? CarbonImmutable::now('UTC'))->getTimestamp()) {
            throw IpcException::sessionInvalid();
        }

        return new IpcSession($client, $sessionId, $expiresAt, IpcSigner::signingKey($key, $client, $sessionId));
    }
}
