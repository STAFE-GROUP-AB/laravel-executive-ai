<?php

declare(strict_types=1);

namespace App\CRM\Contacts;

use DateTimeImmutable;

/**
 * Read model returned by `WorkspaceRepository::contacts()`; see CustomerSummary for
 * why it is not an Eloquent model. `emailAddresses` carries every address known for
 * the contact (blueprint §3.2), lower-cased.
 */
final readonly class ContactSummary
{
    /**
     * @param  list<string>  $emailAddresses
     * @param  list<string>  $customerIds
     */
    public function __construct(
        public string $id,
        public string $organizationId,
        public string $displayName,
        public array $emailAddresses,
        public array $customerIds,
        public DateTimeImmutable $updatedAt,
    ) {}
}
