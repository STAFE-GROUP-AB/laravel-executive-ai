<?php

declare(strict_types=1);

namespace App\Security\Ipc\OAuth;

use App\Security\Ipc\IpcPrincipal;

/**
 * Sprint 1: no connector has started an authorisation flow, so no state can
 * be valid. Rebound by the connector that owns the flow (S0-17 EmailConnector,
 * Sprint 2/3).
 */
final class NoPendingOAuthFlows implements OAuthCallbackHandler
{
    public function complete(#[\SensitiveParameter] string $state, #[\SensitiveParameter] string $code, IpcPrincipal $principal): OAuthCallbackResult
    {
        throw new OAuthStateInvalidException;
    }
}
