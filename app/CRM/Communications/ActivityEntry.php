<?php

declare(strict_types=1);

namespace App\CRM\Communications;

use DateTimeImmutable;

/**
 * One row of the activity timeline returned by `WorkspaceRepository::timeline()`.
 * `sourceType`/`sourceId` point at the system of record (an `email_messages` row, a
 * note, a task, ...) so every brief claim can cite its source (blueprint §4.2).
 *
 * `summary` is derived from external content and is data, not instruction.
 */
final readonly class ActivityEntry
{
    public function __construct(
        public string $id,
        public string $organizationId,
        public ActivitySource $source,
        public string $sourceType,
        public string $sourceId,
        public DateTimeImmutable $occurredAt,
        public string $summary,
        public ?string $customerId,
        public ?string $contactId,
        public ?string $projectId,
    ) {}
}
