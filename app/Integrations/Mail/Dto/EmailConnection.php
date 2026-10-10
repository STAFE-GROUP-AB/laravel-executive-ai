<?php

declare(strict_types=1);

namespace App\Integrations\Mail\Dto;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Blueprint §5.6 `EmailConnection`: one connected account, the read model of an
 * `email_connections` row (ADR-002 D8). This is the policy boundary of criterion 4:
 * every connector call takes exactly one connection, and the connector may read or
 * send only through it.
 *
 * It carries a **reference** to the credential (`secretService`/`secretAccount`,
 * the ADR-003 Keychain item `se.stafe.executive-ai.email` / `<id>`), never the
 * credential. `grantedScopes` is what the provider actually granted,
 * `capabilities` what that lets the connector do; `sendAsAddresses` are the
 * identities the provider lets this account send as (the primary address plus
 * verified aliases) — an `ApprovedOutgoingEmail` whose From is not among them is
 * refused (§5.6 "en agent får aldrig byta From-identitet").
 */
final readonly class EmailConnection
{
    /**
     * @param  list<string>  $grantedScopes
     * @param  list<MailCapability>  $capabilities
     * @param  list<EmailAddress>  $sendAsAddresses
     * @param  list<string>  $syncedFolderIds  provider folder ids the user chose to sync; empty means "connector default"
     */
    public function __construct(
        public string $id,
        public string $organizationId,
        public string $userId,
        public MailProviderKind $provider,
        public EmailAddress $emailAddress,
        public AuthorizationMethod $authorizationMethod,
        public DateTimeImmutable $connectedAt,
        public array $grantedScopes = [],
        public array $capabilities = [MailCapability::Read],
        public array $sendAsAddresses = [],
        public array $syncedFolderIds = [],
        public ?MailServerSettings $serverSettings = null,
        public string $secretService = AuthorizationFlow::DEFAULT_SECRET_SERVICE,
        public ?string $secretAccount = null,
        public ?string $displayName = null,
    ) {
        foreach (['id' => $this->id, 'organizationId' => $this->organizationId, 'userId' => $this->userId] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException("An email connection must have a non-empty {$field}.");
            }
        }

        if (! in_array($this->authorizationMethod, $this->provider->authorizationMethods(), true)) {
            throw new InvalidArgumentException("{$this->provider->label()} does not support the {$this->authorizationMethod->value} authorization method (§5.6).");
        }

        if ($this->provider->requiresServerSettings() && $this->serverSettings === null) {
            throw new InvalidArgumentException("{$this->provider->label()} connections need IMAP/SMTP server settings.");
        }

        if (in_array(MailCapability::Send, $this->capabilities, true) && $this->sendAsAddresses === []) {
            throw new InvalidArgumentException('A connection with the send capability must list the addresses it may send as.');
        }
    }

    /**
     * `communications.account_label` in the S0-18 fixtures:
     * `andreas@example.se (Google Workspace)`.
     */
    public function label(): string
    {
        return "{$this->emailAddress->address} ({$this->provider->label()})";
    }

    /**
     * The Keychain account under `secretService`; defaults to the connection id
     * (ADR-003 table row `se.stafe.executive-ai.email` / `<email_connections.id>`).
     */
    public function secretAccount(): string
    {
        return $this->secretAccount ?? $this->id;
    }

    public function can(MailCapability $capability): bool
    {
        return in_array($capability, $this->capabilities, true);
    }

    public function canSendAs(EmailAddress $from): bool
    {
        if (! $this->can(MailCapability::Send)) {
            return false;
        }

        foreach ($this->sendAsAddresses as $candidate) {
            if ($candidate->equals($from)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether `$address` is one of this account's own identities, which decides
     * `EmailDirection` during normalisation.
     */
    public function owns(EmailAddress $address): bool
    {
        if ($this->emailAddress->equals($address)) {
            return true;
        }

        foreach ($this->sendAsAddresses as $candidate) {
            if ($candidate->equals($address)) {
                return true;
            }
        }

        return false;
    }
}
