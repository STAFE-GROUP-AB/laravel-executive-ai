<?php

declare(strict_types=1);

namespace App\CRM\Queries;

use App\CRM\WorkspaceScope;
use DateTimeImmutable;

/**
 * Blueprint §3 `customers(CustomerQuery)`. `search` is a full-text term for the FTS5
 * index (ADR-002 D4); it is user or model text and is bound as a parameter, never
 * interpolated into SQL.
 */
final readonly class CustomerQuery
{
    /**
     * @param  list<string>  $ids
     */
    public function __construct(
        public WorkspaceScope $scope,
        public ?string $search = null,
        public array $ids = [],
        public ?string $ownerUserId = null,
        public ?DateTimeImmutable $updatedSince = null,
        public QueryPage $page = new QueryPage,
    ) {}
}
