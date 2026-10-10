<?php

declare(strict_types=1);

namespace App\Security\Ipc;

/**
 * A verified session-bound token (IPC contract §3.3): the client and session
 * the MAC binds, when it expires, and the derived signing key that signs
 * requests and responses. The signing key is held for the request only and
 * is never logged, serialised or echoed.
 */
final class IpcSession
{
    public function __construct(
        public readonly IpcClient $client,
        public readonly string $sessionId,
        public readonly int $expiresAt,
        #[\SensitiveParameter] private readonly string $signingKey,
    ) {}

    public function signingKey(): string
    {
        return $this->signingKey;
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['client' => $this->client->value, 'session_id' => $this->sessionId, 'signing_key' => '[REDACTED]'];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new \LogicException('An IPC session cannot be serialised.');
    }

    /** @param array<array-key, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('An IPC session cannot be unserialised.');
    }
}
