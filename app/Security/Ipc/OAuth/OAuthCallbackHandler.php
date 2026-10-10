<?php

declare(strict_types=1);

namespace App\Security\Ipc\OAuth;

use App\Security\Ipc\IpcPrincipal;

/**
 * §6.6: the core looks `state` up in its 10-minute cache, performs the token
 * exchange with the stored PKCE verifier and writes the result through
 * `SecretStore::put` (ADR-003 D4). The email/integration connectors that own
 * those flows arrive in Sprint 2/3; until then `NoPendingOAuthFlows` answers
 * every state with oauth_state_invalid. The `code` must never be logged,
 * echoed or stored by an implementation.
 */
interface OAuthCallbackHandler
{
    /**
     * @throws OAuthStateInvalidException unknown or expired state
     */
    public function complete(#[\SensitiveParameter] string $state, #[\SensitiveParameter] string $code, IpcPrincipal $principal): OAuthCallbackResult;
}
