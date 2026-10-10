<?php

declare(strict_types=1);

namespace App\Integrations\Mail\Dto;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Blueprint §5.6 `authorization(): AuthorizationFlow` — the next step the wizard
 * has to drive, for one of the three `AuthorizationMethod`s:
 *
 * - `OAuthPkce`: open `authorizeUrl` (OAuth 2.1 Authorization Code + PKCE S256) in
 *   `ASWebAuthenticationSession` or the system browser. PHP generated `state` and the
 *   `code_verifier` and keeps the verifier in the cache under the hashed state for
 *   `expiresAt` (ADR-003 D4, 10 minutes). The callback carries exactly `code` and
 *   `state` (`ipc-contract.md` §6.6); PHP exchanges the code and writes the token
 *   through the SecretStore.
 * - `AppPassword`: our own UI collects the app-specific/Bridge password and writes
 *   it straight to the SecretStore item `secretService`/`secretAccount`; the
 *   connector then reads it from there for the connection test. `instructions` are
 *   the guided steps (e.g. where to create an iCloud app-specific password).
 * - `CliSession`: `instructions` tell the user to run the official CLI's login; we
 *   never see or store its token.
 *
 * **This object never carries a secret**: no verifier, no client secret, no
 * password, no token. The constructor refuses an authorize URL that smuggles one.
 * `secretService` is the Keychain `kSecAttrService` (ADR-003 table:
 * `se.stafe.executive-ai.email`), `secretAccount` the `email_connections.id` ULID;
 * both are replaced by `App\Security\Secrets\SecretRef` when S1 lands it.
 */
final readonly class AuthorizationFlow
{
    public const string DEFAULT_SECRET_SERVICE = 'se.stafe.executive-ai.email';

    /**
     * @param  list<string>  $requestedScopes
     * @param  list<string>  $instructions
     */
    public function __construct(
        public AuthorizationMethod $method,
        public string $state,
        public DateTimeImmutable $expiresAt,
        public ?string $authorizeUrl = null,
        public array $requestedScopes = [],
        public string $secretService = self::DEFAULT_SECRET_SERVICE,
        public ?string $secretAccount = null,
        public array $instructions = [],
    ) {
        if (trim($this->state) === '') {
            throw new InvalidArgumentException('An authorization flow needs a state value to correlate the callback (§5.6 `state`).');
        }

        if ($this->method === AuthorizationMethod::OAuthPkce) {
            $this->assertSafeAuthorizeUrl();
        }

        if ($this->method !== AuthorizationMethod::OAuthPkce && $this->authorizeUrl !== null) {
            throw new InvalidArgumentException('Only an OAuth flow opens an authorize URL.');
        }

        if ($this->method === AuthorizationMethod::AppPassword && ($this->secretAccount === null || trim($this->secretAccount) === '')) {
            throw new InvalidArgumentException('An app-password flow must say which SecretStore item the password is written to.');
        }
    }

    public function isExpired(DateTimeImmutable $now): bool
    {
        return $now >= $this->expiresAt;
    }

    private function assertSafeAuthorizeUrl(): void
    {
        if ($this->authorizeUrl === null || ! str_starts_with($this->authorizeUrl, 'https://')) {
            throw new InvalidArgumentException('An OAuth flow must open an https authorize URL.');
        }

        $query = (string) parse_url($this->authorizeUrl, PHP_URL_QUERY);
        parse_str($query, $parameters);

        foreach (['client_secret', 'code_verifier', 'password', 'access_token', 'refresh_token'] as $forbidden) {
            if (array_key_exists($forbidden, $parameters)) {
                throw new InvalidArgumentException('An authorize URL may not carry a secret (§5.6, ADR-003 D4).');
            }
        }

        if (($parameters['code_challenge_method'] ?? null) !== 'S256') {
            throw new InvalidArgumentException('An OAuth flow must use PKCE with the S256 challenge method (§5.6).');
        }

        if (($parameters['state'] ?? null) !== $this->state) {
            throw new InvalidArgumentException('The authorize URL must carry the same state as the flow.');
        }
    }
}
