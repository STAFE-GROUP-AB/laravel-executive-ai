<?php

declare(strict_types=1);

namespace App\CRM\Queries;

use App\CRM\WorkspaceScope;
use DateTimeImmutable;

/**
 * Blueprint §3 `projects(ProjectQuery)`. `openOnly` excludes closed and archived
 * projects; `dueBefore` is what `AssessProjectRisk` (§4.1) filters on.
 */
final readonly class ProjectQuery
{
    /**
     * @param  list<string>  $ids
     */
    public function __construct(
        public WorkspaceScope $scope,
        public ?string $search = null,
        public array $ids = [],
        public ?string $customerId = null,
        public bool $openOnly = true,
        public ?DateTimeImmutable $dueBefore = null,
        public ?DateTimeImmutable $updatedSince = null,
        public QueryPage $page = new QueryPage,
    ) {}
}
