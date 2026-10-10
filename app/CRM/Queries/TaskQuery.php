<?php

declare(strict_types=1);

namespace App\CRM\Queries;

use App\CRM\WorkspaceScope;
use DateTimeImmutable;

/**
 * Blueprint §3 `tasks(TaskQuery)`. Tasks include promised follow-ups (§3.2
 * "komma ihåg lovade uppföljningar"), so `dueBefore` with `openOnly` is the
 * "what is overdue" question every brief asks.
 */
final readonly class TaskQuery
{
    /**
     * @param  list<string>  $ids
     */
    public function __construct(
        public WorkspaceScope $scope,
        public ?string $search = null,
        public array $ids = [],
        public ?string $customerId = null,
        public ?string $projectId = null,
        public ?string $assigneeUserId = null,
        public bool $openOnly = true,
        public ?DateTimeImmutable $dueBefore = null,
        public ?DateTimeImmutable $updatedSince = null,
        public QueryPage $page = new QueryPage,
    ) {}
}
