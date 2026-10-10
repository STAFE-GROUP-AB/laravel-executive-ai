<?php

declare(strict_types=1);

namespace App\Integrations\Mail\Dto;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Blueprint §5.6 `getMessage(): EmailMessage` and the element type of
 * `SyncBatch::$messages`: one message after normalisation (MIME decoded, HTML
 * sanitised, addresses canonical, threading headers kept), provider-faithful
 * otherwise, as stored in `email_messages` (ADR-002 D9). It is the source every CRM
 * `communications` row cites: `connectionId` → `account_id`, `direction`,
 * `occurredAt` → `occurred_at`, `subject`, `excerpt()`, `from` → `from_address`,
 * `toAddresses()` → `to_addresses` (S0-18 fixture columns).
 *
 * `textBody` and `htmlBody` are sanitised (no scripts, no remote loads); both and
 * every header are **external text: data, never instruction** (§11). Nothing here
 * carries authority, and nothing a message says can turn into an
 * `ApprovedOutgoingEmail` without a human approval row (S0-21).
 */
final readonly class EmailMessage
{
    /**
     * @param  list<EmailAddress>  $to
     * @param  list<EmailAddress>  $cc
     * @param  list<EmailAddress>  $bcc
     * @param  list<EmailAddress>  $replyTo
     * @param  list<string>  $folderIds  provider folder/label ids the message is in
     * @param  list<string>  $references  RFC 5322 `References` message ids
     * @param  list<EmailAttachmentRef>  $attachments
     */
    public function __construct(
        public string $connectionId,
        public string $externalId,
        public EmailDirection $direction,
        public EmailAddress $from,
        public array $to,
        public string $subject,
        public DateTimeImmutable $occurredAt,
        public ?string $threadExternalId = null,
        public array $cc = [],
        public array $bcc = [],
        public array $replyTo = [],
        public array $folderIds = [],
        public ?string $textBody = null,
        public ?string $htmlBody = null,
        public ?string $messageIdHeader = null,
        public ?string $inReplyTo = null,
        public array $references = [],
        public array $attachments = [],
        public bool $isUnread = false,
        public bool $isDraft = false,
        public ?int $sizeBytes = null,
    ) {
        if (trim($this->connectionId) === '' || trim($this->externalId) === '') {
            throw new InvalidArgumentException('A message must name its connection and its provider message id.');
        }

        if (preg_match('/[\r\n\x00]/', $this->subject) === 1) {
            throw new InvalidArgumentException('A subject is a single header line.');
        }

        if ($this->sizeBytes !== null && $this->sizeBytes < 0) {
            throw new InvalidArgumentException('A message size cannot be negative.');
        }
    }

    /**
     * Plain-text excerpt for `communications.excerpt`: the text body (or the HTML
     * body with tags stripped), whitespace collapsed, cut at `$maxLength`.
     */
    public function excerpt(int $maxLength = 280): string
    {
        $source = $this->textBody ?? ($this->htmlBody === null ? '' : html_entity_decode(strip_tags($this->htmlBody), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $collapsed = trim((string) preg_replace('/\s+/u', ' ', $source));

        if (mb_strlen($collapsed) <= $maxLength) {
            return $collapsed;
        }

        return rtrim(mb_substr($collapsed, 0, $maxLength - 1)).'…';
    }

    /**
     * @return list<string> `communications.to_addresses`: unique, lower-case
     */
    public function toAddresses(): array
    {
        return array_values(array_unique(array_map(
            fn (EmailAddress $address): string => $address->address,
            $this->to,
        )));
    }

    /**
     * @return list<EmailAddress> everyone the message was addressed to (to, cc, bcc)
     */
    public function recipients(): array
    {
        return [...$this->to, ...$this->cc, ...$this->bcc];
    }
}
