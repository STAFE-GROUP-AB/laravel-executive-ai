<?php

declare(strict_types=1);

namespace App\CRM\Queries;

use App\CRM\Communications\ActivitySource;
use App\CRM\WorkspaceScope;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Blueprint §3 `timeline(ActivityQuery)`: the activity timeline over the
 * `communications` projection (ADR-002 D9), newest first. At least one of
 * `customerId`, `contactId`, `projectId` or a time window must be given so a
 * timeline query is never "everything".
 */
final readonly class ActivityQuery
{
    /**
     * @param  list<ActivitySource>  $sources  empty means all sources
     */
    public function __construct(
        public WorkspaceScope $scope,
        public ?string $customerId = null,
        public ?string $contactId = null,
        public ?string $projectId = null,
        public ?DateTimeImmutable $from = null,
        public ?DateTimeImmutable $until = null,
        public array $sources = [],
        public QueryPage $page = new QueryPage,
    ) {
        $anchored = $this->customerId !== null
            || $this->contactId !== null
            || $this->projectId !== null
            || $this->from !== null;

        if (! $anchored) {
            throw new InvalidArgumentException('A timeline query must be anchored to a customer, contact, project or start time.');
        }

        if ($this->from !== null && $this->until !== null && $this->until < $this->from) {
            throw new InvalidArgumentException('A timeline window must not end before it starts.');
        }
    }
}
