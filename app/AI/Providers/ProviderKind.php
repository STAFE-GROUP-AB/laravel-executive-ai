<?php

declare(strict_types=1);

namespace App\AI\Providers;

/**
 * Where a provider's model runs. `Local` is on the owner's Mac with no key and no
 * egress; `Remote` is the owner's own account with a key the Supervisor stores
 * in the Keychain (ADR-003). Mirrors `kind` in packaging/fixtures/ai-providers.
 */
enum ProviderKind: string
{
    case Local = 'local';
    case Remote = 'remote';
}
