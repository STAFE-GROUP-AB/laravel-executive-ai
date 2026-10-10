<?php

declare(strict_types=1);

namespace App\CRM\Customers;

use DateTimeImmutable;

/**
 * Read model returned by `WorkspaceRepository::customers()`. Deliberately not an
 * Eloquent model: consumers are the brief generator, the `crm.search` MCP tool and
 * the AI tools, which must see a stable, provider-neutral shape that is identical on
 * every database profile (ADR-002 D10). Fields may be added, never removed.
 */
final readonly class CustomerSummary
{
    public function __construct(
        public string $id,
        public string $organizationId,
        public string $name,
        public ?string $legalIdentity,
        public ?string $ownerUserId,
        public string $status,
        public DateTimeImmutable $updatedAt,
    ) {}
}
