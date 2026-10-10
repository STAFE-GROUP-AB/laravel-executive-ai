<?php

declare(strict_types=1);

namespace App\Integrations\Mail\Dto;

use InvalidArgumentException;

/**
 * IMAP and SMTP endpoints for the IMAP family (blueprint §5.2 iCloud, §5.6 "Annan
 * mejl" and Proton Bridge). Transport security is not optional: either implicit TLS
 * on connect or STARTTLS that *must* succeed; a plaintext session is not
 * expressible. Certificate verification is strict (§5.2); the only exception is a
 * loopback Bridge, whose self-signed certificate the user pins explicitly through
 * `bridgeCertificateFingerprint` (SHA-256, hex) — never "accept any".
 *
 * Never carries a password: credentials live in the SecretStore (ADR-003).
 */
final readonly class MailServerSettings
{
    public function __construct(
        public string $imapHost,
        public int $imapPort,
        public string $smtpHost,
        public int $smtpPort,
        public bool $imapStartTls = false,
        public bool $smtpStartTls = true,
        public ?string $bridgeCertificateFingerprint = null,
    ) {
        foreach (['imapHost' => $this->imapHost, 'smtpHost' => $this->smtpHost] as $field => $host) {
            if (trim($host) === '' || preg_match('/[\s\/@:]/', $host) === 1) {
                throw new InvalidArgumentException("The {$field} must be a bare host name or IP address.");
            }
        }

        foreach (['imapPort' => $this->imapPort, 'smtpPort' => $this->smtpPort] as $field => $port) {
            if ($port < 1 || $port > 65535) {
                throw new InvalidArgumentException("The {$field} must be between 1 and 65535.");
            }
        }

        if ($this->bridgeCertificateFingerprint !== null) {
            if (! $this->isLoopback()) {
                throw new InvalidArgumentException('A pinned certificate fingerprint is only allowed for a loopback Bridge.');
            }

            if (preg_match('/^[0-9a-f]{64}$/', $this->bridgeCertificateFingerprint) !== 1) {
                throw new InvalidArgumentException('The Bridge certificate fingerprint must be a lower-case hex SHA-256.');
            }
        }
    }

    /**
     * Proton Mail Bridge defaults (IMAP 1143 / SMTP 1025 on loopback, STARTTLS).
     * Assumed from Bridge documentation; verify against the installed Bridge before
     * S3 ships the adapter.
     */
    public static function protonBridgeDefaults(string $certificateFingerprint): self
    {
        return new self(
            imapHost: '127.0.0.1',
            imapPort: 1143,
            smtpHost: '127.0.0.1',
            smtpPort: 1025,
            imapStartTls: true,
            smtpStartTls: true,
            bridgeCertificateFingerprint: $certificateFingerprint,
        );
    }

    public function isLoopback(): bool
    {
        return in_array($this->imapHost, ['127.0.0.1', '::1', 'localhost'], true)
            && in_array($this->smtpHost, ['127.0.0.1', '::1', 'localhost'], true);
    }
}
