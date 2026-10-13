<?php

declare(strict_types=1);

namespace App\CRM\Search;

/**
 * The entities with a full-text index and the columns it covers (ADR-002 D4).
 * Decided once here so the SQLite FTS5 table and the MySQL FULLTEXT index cover
 * exactly the same text and the `search` contract group can demand the same hit
 * set. Only `string`/`text` columns: InnoDB cannot index JSON, so `tags`,
 * `email_domains` and `to_addresses` are searched by exact lookup, not here.
 */
enum SearchableEntity: string
{
    case Customers = 'customers';
    case Contacts = 'contacts';
    case Communications = 'communications';

    public function table(): string
    {
        return $this->value;
    }

    /** @return list<string> */
    public function columns(): array
    {
        return match ($this) {
            self::Customers => ['display_name', 'legal_name', 'notes'],
            self::Contacts => ['display_name', 'given_name', 'family_name', 'title', 'notes'],
            self::Communications => ['subject', 'excerpt'],
        };
    }

    /** The FTS5 external-content table on the `local` profile. */
    public function ftsTable(): string
    {
        return "{$this->value}_fts";
    }
}
