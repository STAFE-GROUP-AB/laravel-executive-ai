<?php

declare(strict_types=1);

namespace App\Integrations\Mail\Dto;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Blueprint §5.6 `send(): SendReceipt` — proof that the provider accepted exactly
 * one approved email, written to `email_delivery_attempts` and cited by the
 * `action_approvals` row so the same approval can never be sent twice (criterion 7
 * "exakt en gång"; §11 idempotency). `providerMessageId` is the id the provider
 * assigned (Gmail message id, Graph message id); it is null for SMTP, which only
 * returns `250 OK`, in which case `messageIdHeader` — the RFC 5322 `Message-ID` we
 * generated — is the handle for finding the sent copy later.
 */
final readonly class SendReceipt
{
    public function __construct(
        public string $connectionId,
        public string $proposalId,
        public string $approvalId,
        public string $nonce,
        public string $bodyHash,
        public DateTimeImmutable $sentAt,
        public ?string $providerMessageId = null,
        public ?string $messageIdHeader = null,
        public ?string $threadExternalId = null,
    ) {
        foreach ([
            'connectionId' => $this->connectionId,
            'proposalId' => $this->proposalId,
            'approvalId' => $this->approvalId,
            'nonce' => $this->nonce,
        ] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException("A send receipt must carry a non-empty {$field}.");
            }
        }

        if (preg_match('/^[0-9a-f]{64}$/', $this->bodyHash) !== 1) {
            throw new InvalidArgumentException('A send receipt carries the lower-case hex SHA-256 body hash that was sent.');
        }

        if ($this->providerMessageId === null && $this->messageIdHeader === null) {
            throw new InvalidArgumentException('A send receipt must carry a provider message id or the Message-ID header, so the sent copy can be found.');
        }
    }
}
