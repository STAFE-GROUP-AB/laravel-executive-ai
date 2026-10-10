<?php

declare(strict_types=1);

namespace App\Integrations\Mail\Dto;

use InvalidArgumentException;

/**
 * Blueprint §5.6 `authorization(EmailConnectionDraft)`: what the Add Email Account
 * wizard knows *before* any credential exists — who is connecting, which provider
 * was detected (the user may have corrected it), which address, which method and
 * which scopes will be shown on the consent screen.
 *
 * `requestedScopes` is the complete list the connector will ask for; it is shown to
 * the user verbatim ("visa minsta scopes och rättigheter") and stored on the
 * connection as `grantedScopes` after consent. A connector may not add a scope the
 * draft did not carry. For `OAuthPkce` the list is mandatory and `callbackUri` is
 * the redirect the IPC `oauth/callback` or PWA loopback route will receive
 * (ADR-003 D4; `ipc-contract.md` §6.6) — it must be on the redirect allowlist.
 */
final readonly class EmailConnectionDraft
{
    /**
     * @param  list<string>  $requestedScopes
     */
    public function __construct(
        public string $organizationId,
        public string $userId,
        public MailProviderKind $provider,
        public EmailAddress $emailAddress,
        public AuthorizationMethod $authorizationMethod,
        public array $requestedScopes = [],
        public ?string $callbackUri = null,
        public ?MailServerSettings $serverSettings = null,
    ) {
        if (trim($this->organizationId) === '' || trim($this->userId) === '') {
            throw new InvalidArgumentException('A connection draft must name its organization and user (§3.1 organization_id, §5.6 per-user accounts).');
        }

        if (! in_array($this->authorizationMethod, $this->provider->authorizationMethods(), true)) {
            throw new InvalidArgumentException("{$this->provider->label()} does not support the {$this->authorizationMethod->value} authorization method (§5.6).");
        }

        if ($this->authorizationMethod === AuthorizationMethod::OAuthPkce) {
            if ($this->requestedScopes === []) {
                throw new InvalidArgumentException('An OAuth connection must list the scopes it will request; implicit scopes are not allowed (§5.6).');
            }

            if ($this->callbackUri === null || trim($this->callbackUri) === '') {
                throw new InvalidArgumentException('An OAuth connection must name the callback URI the authorization will redirect to (ADR-003 D4).');
            }
        }

        foreach ($this->requestedScopes as $scope) {
            if (trim($scope) === '' || preg_match('/\s/', $scope) === 1) {
                throw new InvalidArgumentException('Scopes must be non-empty tokens without whitespace.');
            }
        }

        if ($this->provider->requiresServerSettings() && $this->serverSettings === null) {
            throw new InvalidArgumentException("{$this->provider->label()} connections need IMAP/SMTP server settings before authorization can start.");
        }

        if ($this->provider === MailProviderKind::ProtonBridge && ! $this->serverSettings?->isLoopback()) {
            throw new InvalidArgumentException('Proton Mail is connected only through a local Bridge, never through cloud IMAP (§5.6).');
        }
    }
}
