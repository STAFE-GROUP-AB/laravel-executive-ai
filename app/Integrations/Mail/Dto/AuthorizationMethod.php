<?php

declare(strict_types=1);

namespace App\Integrations\Mail\Dto;

/**
 * How a connection obtains its credential (blueprint §5.6 "Primär säker anslutning"
 * column; ADR-003 envelope kinds `oauth2` and `password`).
 *
 * - `OAuthPkce`: OAuth 2.0/2.1 Authorization Code + PKCE through the system browser
 *   or `ASWebAuthenticationSession`; PHP holds `state` and the verifier (ADR-003 D4).
 * - `AppPassword`: an app-specific password (iCloud), a Bridge-issued password
 *   (Proton) or a plain IMAP password typed by the user into our own UI and written
 *   straight to the SecretStore; never an embedded web view.
 * - `CliSession`: the official HEY CLI's own authorization; we never see a token.
 */
enum AuthorizationMethod: string
{
    case OAuthPkce = 'oauth_pkce';
    case AppPassword = 'app_password';
    case CliSession = 'cli_session';
}
