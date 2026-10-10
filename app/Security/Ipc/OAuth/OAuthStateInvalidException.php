<?php

declare(strict_types=1);

namespace App\Security\Ipc\OAuth;

use RuntimeException;

/**
 * The presented `state` is unknown or expired (§6.6 → 422 /
 * oauth_state_invalid). The message never includes the state or the code.
 */
final class OAuthStateInvalidException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The OAuth state is unknown or has expired.');
    }
}
