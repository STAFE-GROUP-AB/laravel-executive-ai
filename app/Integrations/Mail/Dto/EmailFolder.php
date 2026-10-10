<?php

declare(strict_types=1);

namespace App\Integrations\Mail\Dto;

use InvalidArgumentException;

/**
 * Blueprint §5.6 `folders(): iterable` element — one provider folder (Gmail label,
 * Graph mailFolder, IMAP mailbox) with its neutral role so the wizard's
 * "val av mappar" and the unified inbox work the same for every provider.
 * `externalId` is what `SyncCursor::$scope` and `EmailConnection::$syncedFolderIds`
 * refer to. Counts are provider-reported and informational.
 */
final readonly class EmailFolder
{
    public function __construct(
        public string $externalId,
        public string $name,
        public EmailFolderRole $role,
        public ?string $parentExternalId = null,
        public ?int $totalCount = null,
        public ?int $unreadCount = null,
        public bool $selectable = true,
    ) {
        if (trim($this->externalId) === '' || trim($this->name) === '') {
            throw new InvalidArgumentException('A folder needs an id and a name.');
        }

        if (($this->totalCount !== null && $this->totalCount < 0) || ($this->unreadCount !== null && $this->unreadCount < 0)) {
            throw new InvalidArgumentException('Folder counts cannot be negative.');
        }
    }
}
