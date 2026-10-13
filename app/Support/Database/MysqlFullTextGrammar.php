<?php

declare(strict_types=1);

namespace App\Support\Database;

use App\CRM\Search\SearchableEntity;
use App\CRM\Search\SearchTerms;

/**
 * The `server` profile side of ADR-002 D4: InnoDB FULLTEXT indexes over the same
 * columns as the FTS5 tables (created portably with Blueprint::fullText in the
 * migration) queried with MATCH … AGAINST (… IN BOOLEAN MODE). `+term` makes
 * every term required, the AND the FTS5 side gets implicitly.
 *
 * Only this class may write MySQL full-text SQL (ADR-002 D10).
 */
final class MysqlFullTextGrammar
{
    public static function indexName(SearchableEntity $entity): string
    {
        return "{$entity->table()}_fulltext";
    }

    /**
     * Bindings, in order: the AGAINST expression from compileMatch(), the
     * organization id, the limit.
     */
    public static function compileSearch(SearchableEntity $entity): string
    {
        $table = $entity->table();
        $columns = implode(', ', array_map(static fn (string $column): string => "b.{$column}", $entity->columns()));

        return "SELECT b.id FROM {$table} AS b"
            ." WHERE MATCH({$columns}) AGAINST (? IN BOOLEAN MODE) AND b.organization_id = ? AND b.deleted_at IS NULL"
            .' ORDER BY b.id LIMIT ?';
    }

    /** @param  list<string>  $terms */
    public static function compileMatch(array $terms): string
    {
        return implode(' ', array_map(static function (string $term): string {
            $word = SearchTerms::word($term);

            return SearchTerms::isPrefix($term) ? "+{$word}*" : "+{$word}";
        }, $terms));
    }
}
