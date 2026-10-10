<?php

declare(strict_types=1);

namespace App\Security\Secrets;

/**
 * Fail closed: no socket, no secrets. A developer checkout without the
 * Supervisor runs with this store and APP_KEY from .env; every IPC request
 * answers 503 secrets_unavailable.
 */
final class UnavailableSecretStore implements SecretStore
{
    public function get(SecretRef $ref): SecretValue
    {
        throw new SecretUnavailableException('unavailable: no secrets broker is configured.');
    }

    public function put(SecretRef $ref, #[\SensitiveParameter] string $envelope): void
    {
        throw new SecretUnavailableException('unavailable: no secrets broker is configured.');
    }

    public function delete(SecretRef $ref): void
    {
        throw new SecretUnavailableException('unavailable: no secrets broker is configured.');
    }

    public function list(string $service): array
    {
        throw new SecretUnavailableException('unavailable: no secrets broker is configured.');
    }
}
