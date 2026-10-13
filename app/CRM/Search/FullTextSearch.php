<?php

declare(strict_types=1);

namespace App\CRM\Search;

/**
 * ADR-002 D4: the internal full-text contract over the CRM. The `local` profile
 * answers from the FTS5 external-content tables `customers_fts`, `contacts_fts`
 * and `communications_fts` (trigger-maintained, tokenizer `unicode61
 * remove_diacritics 2`); the `server` profile from InnoDB FULLTEXT indexes on the
 * same columns with `MATCH … AGAINST (… IN BOOLEAN MODE)`.
 *
 * Contract both implementations honour, asserted by the `search` contract group:
 * - every term in `$query` must match (AND); a trailing `*` matches a prefix;
 *   everything else in the input is ignored (SearchTerms).
 * - diacritics are folded: `goteborg` finds "Göteborg", `sjostrom` finds
 *   "Sjöström" — decided against the Swedish fixture corpus (ADR-002 D4).
 * - rows outside `$organizationId` and soft-deleted rows are never returned.
 * - the result is the hit SET as ids in ascending order, at most `$limit` long;
 *   relevance ranking is not part of the contract and may differ per profile.
 *
 * Known profile differences, kept out of the contract queries: InnoDB ignores
 * terms shorter than `innodb_ft_min_token_size` (3) and its stopword list;
 * `utf8mb4_unicode_ci` also folds ß → ss, unicode61 does not; InnoDB only sees
 * rows whose transaction has committed.
 */
interface FullTextSearch
{
    /** @return list<string> ids of the matching rows, ascending */
    public function search(SearchableEntity $entity, string $organizationId, string $query, int $limit = 50): array;
}
