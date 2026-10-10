<?php

declare(strict_types=1);

namespace App\Security\Ipc;

/**
 * The fail-closed default until S1-07 binds the secrets-broker source
 * (ADR-003 D3): no key, so `/ipc/*` and `/health/ready` answer 503 /
 * secrets_unavailable. Nothing is read from .env, a plist or a file.
 */
final class UnavailableKeySource implements IpcKeySource
{
    public function key(): IpcKey
    {
        throw new IpcKeyUnavailableException('No IPC key source is bound; the secrets broker (S1-07) has not been installed.');
    }
}
