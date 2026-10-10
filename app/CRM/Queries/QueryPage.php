<?php

declare(strict_types=1);

namespace App\CRM\Queries;

use InvalidArgumentException;

/**
 * Keyset pagination shared by every `*Query` (blueprint §3 returns iterables; a page
 * is bounded so the brief generator and `crm.search` tool can never pull a whole
 * table into a prompt). `after` is the ULID of the last row of the previous page.
 */
final readonly class QueryPage
{
    public const int MAX_LIMIT = 500;

    public function __construct(
        public int $limit = 50,
        public ?string $after = null,
    ) {
        if ($this->limit < 1) {
            throw new InvalidArgumentException('A query page needs a limit of at least 1.');
        }

        if ($this->limit > self::MAX_LIMIT) {
            throw new InvalidArgumentException('A query page may not exceed '.self::MAX_LIMIT.' rows.');
        }
    }
}
