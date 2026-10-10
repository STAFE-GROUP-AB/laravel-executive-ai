<?php

declare(strict_types=1);

namespace App\Integrations\Mail\Dto;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Blueprint §5.6 `send(EmailConnection, ApprovedOutgoingEmail)`: an outgoing email
 * plus the proof that a human approved exactly this email (§11 frozen immutable
 * proposal: recipients, hash, approver identity, expiry, nonce; §5.6 criterion 8).
 * Modelled on `App\Browser\ComputerUse\AuthorizedUiAction`.
 *
 * **Only the policy layer (`App\Approvals`) constructs this class**, after matching
 * an `action_proposals` row to its `action_approvals` row and consuming the nonce.
 * Agents, tools, MCP handlers, Livewire actions and anything that touches model
 * output or external text may not instantiate it;
 * `tests/Unit/Contracts/AuthorizedActionProvenanceTest.php` enforces that with a
 * source scan, and S0-21 builds the negative security test on it.
 *
 * Sender, recipients, subject and body are immutable once approved: the From
 * address must be one the connection may send as, `to` is non-empty, every address
 * is canonical (`EmailAddress`), and `bodyHash` is `hashBody()` over the body and
 * the attachments manifest. The constructor verifies the hash against the content
 * it carries, and the connector recomputes it from the MIME it is about to hand to
 * the provider and refuses on mismatch (`SendRefusedException::BodyHashMismatch`),
 * so an approval can never be replayed against different content.
 *
 * The connector uses only `connectionId` to pick the account; `from` is checked
 * against `EmailConnection::canSendAs()`, never used to look an account up
 * (criterion 4: account A can never send through account B).
 */
final readonly class ApprovedOutgoingEmail
{
    /**
     * @param  list<EmailAddress>  $to
     * @param  list<EmailAddress>  $cc
     * @param  list<EmailAddress>  $bcc
     * @param  list<EmailAttachmentRef>  $attachments  manifest; every entry carries its sha256
     * @param  string  $bodyHash  `hashBody()` over the content below, computed when the proposal was frozen
     */
    public function __construct(
        public string $proposalId,
        public string $approvalId,
        public string $approverUserId,
        public string $connectionId,
        public EmailAddress $from,
        public array $to,
        public array $cc,
        public array $bcc,
        public string $subject,
        public string $textBody,
        public ?string $htmlBody,
        public array $attachments,
        public string $bodyHash,
        public string $nonce,
        public DateTimeImmutable $approvedAt,
        public DateTimeImmutable $expiresAt,
        public ?string $inReplyToExternalId = null,
    ) {
        foreach ([
            'proposalId' => $this->proposalId,
            'approvalId' => $this->approvalId,
            'approverUserId' => $this->approverUserId,
            'connectionId' => $this->connectionId,
            'nonce' => $this->nonce,
        ] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException("An approved email must carry a non-empty {$field} (§11).");
            }
        }

        if ($this->to === []) {
            throw new InvalidArgumentException('An approved email must have at least one To recipient.');
        }

        if (preg_match('/[\r\n\x00]/', $this->subject) === 1) {
            throw new InvalidArgumentException('A subject is a single header line.');
        }

        if ($this->expiresAt <= $this->approvedAt) {
            throw new InvalidArgumentException('An approval must expire after it was granted.');
        }

        foreach ($this->attachments as $attachment) {
            if ($attachment->sha256 === null) {
                throw new InvalidArgumentException('Every attachment in an outgoing manifest must carry its sha256.');
            }
        }

        if ($this->bodyHash !== self::hashBody($this->textBody, $this->htmlBody, $this->attachments)) {
            throw new InvalidArgumentException('The body hash does not match the approved content.');
        }
    }

    /**
     * The one definition of the §11 body hash, shared by the policy layer (when it
     * freezes the proposal) and every connector (when it is about to send):
     * SHA-256 over the canonical text body, the canonical HTML body and the
     * attachments manifest sorted by filename, each field length-prefixed so no
     * boundary can be shifted. Canonical means CRLF/CR folded to LF and trailing
     * whitespace on the whole body removed.
     *
     * @param  list<EmailAttachmentRef>  $attachments
     */
    public static function hashBody(string $textBody, ?string $htmlBody, array $attachments): string
    {
        $manifest = array_map(
            fn (EmailAttachmentRef $attachment): string => implode("\x1f", [
                $attachment->filename,
                $attachment->mimeType,
                (string) $attachment->sizeBytes,
                (string) $attachment->sha256,
            ]),
            $attachments,
        );
        sort($manifest, SORT_STRING);

        $fields = [
            self::canonicalBody($textBody),
            self::canonicalBody($htmlBody ?? ''),
            ...$manifest,
        ];

        $buffer = '';

        foreach ($fields as $field) {
            $buffer .= strlen($field)."\x1e".$field."\x1e";
        }

        return hash('sha256', $buffer);
    }

    public function isExpired(DateTimeImmutable $now): bool
    {
        return $now >= $this->expiresAt;
    }

    /**
     * @return list<EmailAddress> to, cc and bcc in that order
     */
    public function recipients(): array
    {
        return [...$this->to, ...$this->cc, ...$this->bcc];
    }

    private static function canonicalBody(string $body): string
    {
        return rtrim((string) preg_replace('/\r\n?/', "\n", $body));
    }
}
