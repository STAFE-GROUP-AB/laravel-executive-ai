<?php

declare(strict_types=1);

namespace App\CRM\Queries;

use App\CRM\WorkspaceScope;
use DateTimeImmutable;

/**
 * Blueprint §3 `contacts(ContactQuery)`. `emailAddress` matches any of a contact's
 * addresses (§3.2: several addresses per contact) and is the lookup `ResolveIdentity`
 * (§4.1) uses.
 */
final readonly class ContactQuery
{
    /**
     * @param  list<string>  $ids
     */
    public function __construct(
        public WorkspaceScope $scope,
        public ?string $search = null,
        public array $ids = [],
        public ?string $customerId = null,
        public ?string $emailAddress = null,
        public ?DateTimeImmutable $updatedSince = null,
        public QueryPage $page = new QueryPage,
    ) {}
}
