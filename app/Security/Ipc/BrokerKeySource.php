<?php

declare(strict_types=1);

namespace App\Security\Ipc;

use App\Security\Secrets\SecretRef;
use App\Security\Secrets\SecretStore;
use App\Security\Secrets\SecretUnavailableException;

/**
 * S1-07's binding of the key-source seam (docs/architecture/ipc-server.md §2):
 * `K` is read from the `se.stafe.executive-ai.ipc.menubar` envelope through
 * the Supervisor's secrets broker on every call, so "Reset security pairing"
 * takes effect on the next request. Never from env, .env, a plist or a file.
 */
final class BrokerKeySource implements IpcKeySource
{
    public function __construct(private readonly SecretStore $store) {}

    public function key(): IpcKey
    {
        try {
            $envelope = $this->store->get(SecretRef::ipcKey())->envelope();
        } catch (SecretUnavailableException $exception) {
            throw new IpcKeyUnavailableException('The IPC key could not be read from the secrets broker.', previous: $exception);
        }

        $encoded = $envelope['key'] ?? null;

        if (($envelope['kind'] ?? null) !== 'hmac_key' || ! is_string($encoded)) {
            throw new IpcKeyUnavailableException('The IPC key envelope is not an hmac_key envelope.');
        }

        $bytes = base64_decode($encoded, true);

        if ($bytes === false) {
            throw new IpcKeyUnavailableException('The IPC key envelope is not base64.');
        }

        try {
            return IpcKey::fromBytes($bytes);
        } catch (\InvalidArgumentException $exception) {
            throw new IpcKeyUnavailableException('The IPC key envelope does not hold a valid key.', previous: $exception);
        }
    }
}
