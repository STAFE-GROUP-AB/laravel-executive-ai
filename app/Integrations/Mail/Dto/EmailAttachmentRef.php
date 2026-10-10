<?php

declare(strict_types=1);

namespace App\Integrations\Mail\Dto;

use InvalidArgumentException;

/**
 * A reference to an attachment, never its bytes (blueprint §5.6 `email_attachments`
 * "referenser"; "bilagor öppnas aldrig automatiskt"). `externalId` is the provider's
 * part/attachment id for inbound mail or our own `email_attachments` id for an
 * outgoing manifest. `filename` is reduced to a base name so a provider-supplied
 * name can never become a path. `sha256` is required in an outgoing manifest
 * (`ApprovedOutgoingEmail::hashBody()`), optional for inbound references.
 */
final readonly class EmailAttachmentRef
{
    public function __construct(
        public string $externalId,
        public string $filename,
        public string $mimeType,
        public int $sizeBytes,
        public bool $isInline = false,
        public ?string $contentId = null,
        public ?string $sha256 = null,
    ) {
        if (trim($this->externalId) === '') {
            throw new InvalidArgumentException('An attachment reference needs an id.');
        }

        if ($this->filename !== basename($this->filename) || preg_match('/[\r\n\x00]/', $this->filename) === 1) {
            throw new InvalidArgumentException('An attachment filename must be a bare file name without path segments or control characters.');
        }

        if (preg_match('#^[\w.+-]+/[\w.+-]+$#', $this->mimeType) !== 1) {
            throw new InvalidArgumentException('An attachment MIME type must be `type/subtype`.');
        }

        if ($this->sizeBytes < 0) {
            throw new InvalidArgumentException('An attachment size cannot be negative.');
        }

        if ($this->sha256 !== null && preg_match('/^[0-9a-f]{64}$/', $this->sha256) !== 1) {
            throw new InvalidArgumentException('An attachment digest must be a lower-case hex SHA-256.');
        }
    }
}
