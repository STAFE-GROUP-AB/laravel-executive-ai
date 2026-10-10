<?php

declare(strict_types=1);

namespace App\Security\Secrets;

/**
 * The PHP contract of ADR-003 D3.4. `get` is a broker round trip every time
 * (nothing is cached beyond the caller's local); `list` returns accounts and
 * never values; `rotate` is deferred to the item that first needs it.
 */
interface SecretStore
{
    /**
     * @throws SecretUnavailableException when the item does not exist, the broker is locked or unreachable
     */
    public function get(SecretRef $ref): SecretValue;

    /**
     * @throws SecretUnavailableException
     */
    public function put(SecretRef $ref, #[\SensitiveParameter] string $envelope): void;

    /**
     * @throws SecretUnavailableException
     */
    public function delete(SecretRef $ref): void;

    /**
     * @return list<string> accounts under the service
     *
     * @throws SecretUnavailableException
     */
    public function list(string $service): array;
}
