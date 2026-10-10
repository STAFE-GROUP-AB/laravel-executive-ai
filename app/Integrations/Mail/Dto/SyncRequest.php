<?php

declare(strict_types=1);

namespace App\Integrations\Mail\Dto;

use InvalidArgumentException;

/**
 * One page of `EmailConnector::sync()`: resume from `cursor`, return at most
 * `limit` items (messages plus tombstones). The bound is the ADR-002 D3.2 batch
 * size (100–500 rows per transaction) so a sync job can commit the page and the
 * cursor together; a connector that cannot honour the provider's own page size
 * must split, never exceed. `includeBodies` false is the bootstrap mode for very
 * large mailboxes: headers now, bodies on `getMessage()` later.
 */
final readonly class SyncRequest
{
    public const int DEFAULT_LIMIT = 200;

    public const int MAX_LIMIT = 500;

    public function __construct(
        public SyncCursor $cursor,
        public int $limit = self::DEFAULT_LIMIT,
        public bool $includeBodies = true,
    ) {
        if ($this->limit < 1 || $this->limit > self::MAX_LIMIT) {
            throw new InvalidArgumentException('A sync page requests between 1 and '.self::MAX_LIMIT.' items (ADR-002 D3.2).');
        }
    }
}
