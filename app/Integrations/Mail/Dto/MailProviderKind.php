<?php

declare(strict_types=1);

namespace App\Integrations\Mail\Dto;

/**
 * The six provider families of the blueprint §5.6 table. One `EmailConnector`
 * implementation serves one kind; the registry picks the connector from the
 * `email_connections.provider` column (ADR-002 D8). The string values are the wire
 * values used by the IPC `oauth/callback` response (`ipc-contract.md` §6.6).
 *
 * `ProtonBridge` is the local Proton Mail Bridge (IMAP/SMTP on loopback with a
 * Bridge-issued password), never Proton's cloud IMAP; `Imap` is "Other email" with
 * autodiscovered or manual server settings. iCloud is IMAP/SMTP with an app-specific
 * password because §5.6 forbids promising a general iCloud Mail OAuth.
 */
enum MailProviderKind: string
{
    case Google = 'google';
    case Microsoft = 'microsoft';
    case ICloud = 'icloud';
    case Hey = 'hey';
    case Imap = 'imap';
    case ProtonBridge = 'proton_bridge';

    /**
     * User-facing provider label, the parenthesised part of
     * `communications.account_label` in the S0-18 fixtures.
     */
    public function label(): string
    {
        return match ($this) {
            self::Google => 'Google Workspace',
            self::Microsoft => 'Microsoft 365',
            self::ICloud => 'iCloud Mail',
            self::Hey => 'HEY',
            self::Imap => 'IMAP',
            self::ProtonBridge => 'Proton Mail Bridge',
        };
    }

    /**
     * The authorization methods §5.6 allows for this kind. Anything else is refused
     * by `EmailConnectionDraft` so no adapter can quietly fall back to a password
     * prompt for Google or Microsoft ("aldrig användarlösenord").
     *
     * @return list<AuthorizationMethod>
     */
    public function authorizationMethods(): array
    {
        return match ($this) {
            self::Google, self::Microsoft => [AuthorizationMethod::OAuthPkce],
            self::ICloud, self::ProtonBridge => [AuthorizationMethod::AppPassword],
            self::Hey => [AuthorizationMethod::CliSession],
            self::Imap => [AuthorizationMethod::OAuthPkce, AuthorizationMethod::AppPassword],
        };
    }

    /**
     * Whether `EmailConnectionDraft::$serverSettings` is mandatory: the API
     * providers know their endpoints, the IMAP/SMTP family does not.
     */
    public function requiresServerSettings(): bool
    {
        return match ($this) {
            self::ICloud, self::Imap, self::ProtonBridge => true,
            self::Google, self::Microsoft, self::Hey => false,
        };
    }
}
