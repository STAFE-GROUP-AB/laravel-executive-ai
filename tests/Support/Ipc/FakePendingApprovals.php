<?php

declare(strict_types=1);

namespace Tests\Support\Ipc;

use App\Support\Health\PendingApproval;
use App\Support\Health\PendingApprovals;
use App\Support\Health\PendingApprovalsPage;
use Carbon\CarbonImmutable;

/**
 * A read model seeded from the §6.3 fixture. The real one arrives with
 * action_proposals (E2-4).
 */
final class FakePendingApprovals implements PendingApprovals
{
    /** @param list<PendingApproval> $items */
    public function __construct(private readonly array $items, private readonly ?string $nextCursor = null) {}

    /** @param array<int, array<string, mixed>> $fixtureItems the `data.items` of approvals-pending.json */
    public static function fromFixtureItems(array $fixtureItems): self
    {
        return new self(array_map(static fn (array $item): PendingApproval => new PendingApproval(
            (string) $item['id'],
            (string) $item['kind'],
            (string) $item['title'],
            (string) $item['summary'],
            CarbonImmutable::parse((string) $item['created_at']),
            CarbonImmutable::parse((string) $item['expires_at']),
            $item['brief_item_id'] === null ? null : (string) $item['brief_item_id'],
        ), array_values($fixtureItems)));
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function page(int $limit, ?string $cursor = null): PendingApprovalsPage
    {
        return new PendingApprovalsPage(array_slice($this->items, 0, $limit), $this->nextCursor);
    }
}
