<?php

declare(strict_types=1);

namespace App\CRM\Projects;

use DateTimeImmutable;

/**
 * Read model returned by `WorkspaceRepository::projects()`; see CustomerSummary for
 * why it is not an Eloquent model.
 */
final readonly class ProjectSummary
{
    public function __construct(
        public string $id,
        public string $organizationId,
        public string $customerId,
        public string $name,
        public string $status,
        public ?DateTimeImmutable $dueAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
