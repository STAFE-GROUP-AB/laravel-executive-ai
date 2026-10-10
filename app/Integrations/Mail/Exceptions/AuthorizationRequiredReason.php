<?php

declare(strict_types=1);

namespace App\Integrations\Mail\Exceptions;

/**
 * Why a connection needs the user again (blueprint §5.6 criterion 5). The reason is
 * a category, never provider prose, so it can be shown and logged without carrying
 * a token, an account name or an error page (ADR-003 D7).
 */
enum AuthorizationRequiredReason: string
{
    case TokenExpired = 'token_expired';
    case TokenRevoked = 'token_revoked';
    case ConsentRequired = 'consent_required';
    case ScopeMissing = 'scope_missing';
    case PasswordRejected = 'password_rejected';
    case CliSessionMissing = 'cli_session_missing';
    case SecretMissing = 'secret_missing';
}
