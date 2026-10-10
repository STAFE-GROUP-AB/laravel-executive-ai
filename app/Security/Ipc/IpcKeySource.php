<?php

declare(strict_types=1);

namespace App\Security\Ipc;

/**
 * Where the core obtains `K` at request time (ADR-003 D3: through
 * `SecretStore::get`, held as a SecretValue, never cached to disk). This is
 * the Sprint 1 seam: S1-07 binds the secrets-broker implementation; until
 * then the container holds `UnavailableKeySource` (fail closed, every
 * authenticated route answers 503 / secrets_unavailable) or, only under
 * APP_ENV=testing, `TestingKeySource` with the §4.6 test vector.
 *
 * Implementations re-read the key on every call so that a `secrets.changed`
 * notification after "Reset security pairing" takes effect on the next
 * request (§3.4).
 */
interface IpcKeySource
{
    /**
     * @throws IpcKeyUnavailableException when the broker is down or locked (ADR-003 D3.2)
     */
    public function key(): IpcKey;
}
