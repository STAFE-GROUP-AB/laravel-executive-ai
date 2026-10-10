<?php

declare(strict_types=1);

namespace App\CRM\Tasks;

use DateTimeImmutable;

/**
 * Read model returned by `WorkspaceRepository::tasks()`; see CustomerSummary for why
 * it is not an Eloquent model.
 */
final readonly class TaskSummary
{
    /**
     * @param  list<string>  $assigneeUserIds
     */
    public function __construct(
        public string $id,
        public string $organizationId,
        public ?string $customerId,
        public ?string $projectId,
        public string $title,
        public string $status,
        public array $assigneeUserIds,
        public ?DateTimeImmutable $dueAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
