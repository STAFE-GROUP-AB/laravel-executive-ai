<?php

declare(strict_types=1);

namespace App\Integrations\Mail\Exceptions;

use RuntimeException;

/**
 * The stored credential no longer works and silent refresh is not possible:
 * refresh token expired or revoked, tenant consent withdrawn, scope missing,
 * app-specific password changed, HEY CLI session gone, Keychain item absent.
 *
 * Callers mark the connection `authorization_required`, stop scheduling sync for
 * it, and show `Reconnect` (blueprint §5.6 criterion 5). Nothing here is a reason to
 * try another connection: criterion 4 forbids reading or sending through account B
 * because account A failed.
 */
final class AuthorizationRequiredException extends RuntimeException
{
    public function __construct(
        public readonly string $connectionId,
        public readonly AuthorizationRequiredReason $reason,
        string $message = 'This email account needs to be reconnected.',
    ) {
        parent::__construct($message);
    }
}
