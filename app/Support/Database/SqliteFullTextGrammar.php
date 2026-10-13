<?php

declare(strict_types=1);

namespace App\Support\Database;

use App\CRM\Search\SearchableEntity;
use App\CRM\Search\SearchTerms;
use Illuminate\Database\Connection;

/**
 * The SQLite side of ADR-002 D4: one external-content FTS5 table per searchable
 * entity, kept in step with its content table by AFTER INSERT/UPDATE/DELETE
 * triggers, tokenizer `unicode61 remove_diacritics 2`.
 *
 * Why `remove_diacritics 2` (the decision ADR-002 D4 deferred to a Swedish
 * corpus): the fixture pack's "Göteborg", "Sjöström", "Åkersberga", "Mölndal"
 * must be found by `goteborg`, `sjostrom`, `akersberga`, `molndal` — what a
 * person types on a non-Swedish keyboard — and MySQL's `utf8mb4_unicode_ci`
 * FULLTEXT already folds that way, so folding is also what makes the two
 * profiles return the same hit set. The precision cost (`har` now also finds
 * "här") is accepted for a CRM lookup over names and subjects.
 *
 * External content keys the index by the content table's implicit rowid.
 * Measured on SQLite 3.45.2: rowids of a `char(26)`-keyed table survive both
 * `VACUUM` and `VACUUM INTO` (ADR-002 D5), so a snapshot keeps a valid index;
 * `rebuild()` is the safeguard a restore drill runs anyway.
 *
 * This class is the only place FTS5 SQL may be written (ADR-002 D10).
 */
final class SqliteFullTextGrammar
{
    public const string TOKENIZER = 'unicode61 remove_diacritics 2';

    public static function createIndex(Connection $connection, SearchableEntity $entity): void
    {
        foreach (self::compileCreateIndex($entity) as $statement) {
            $connection->statement($statement);
        }
    }

    /** @return list<string> */
    public static function compileCreateIndex(SearchableEntity $entity): array
    {
        $table = $entity->table();
        $fts = $entity->ftsTable();
        $columns = implode(', ', $entity->columns());
        $new = implode(', ', array_map(static fn (string $column): string => "new.{$column}", $entity->columns()));
        $old = implode(', ', array_map(static fn (string $column): string => "old.{$column}", $entity->columns()));
        $tokenizer = self::TOKENIZER;

        return [
            "CREATE VIRTUAL TABLE IF NOT EXISTS {$fts} USING fts5({$columns}, content='{$table}', content_rowid='rowid', tokenize='{$tokenizer}')",
            "CREATE TRIGGER IF NOT EXISTS {$fts}_after_insert AFTER INSERT ON {$table} BEGIN"
                ." INSERT INTO {$fts}(rowid, {$columns}) VALUES (new.rowid, {$new});"
                .' END',
            "CREATE TRIGGER IF NOT EXISTS {$fts}_after_delete AFTER DELETE ON {$table} BEGIN"
                ." INSERT INTO {$fts}({$fts}, rowid, {$columns}) VALUES ('delete', old.rowid, {$old});"
                .' END',
            "CREATE TRIGGER IF NOT EXISTS {$fts}_after_update AFTER UPDATE OF {$columns} ON {$table} BEGIN"
                ." INSERT INTO {$fts}({$fts}, rowid, {$columns}) VALUES ('delete', old.rowid, {$old});"
                ." INSERT INTO {$fts}(rowid, {$columns}) VALUES (new.rowid, {$new});"
                .' END',
            "INSERT INTO {$fts}({$fts}) VALUES ('rebuild')",
        ];
    }

    /** Re-reads the content table into the index (after a restore, ADR-002 D5). */
    public static function rebuild(Connection $connection, SearchableEntity $entity): void
    {
        $fts = $entity->ftsTable();

        $connection->statement("INSERT INTO {$fts}({$fts}) VALUES ('rebuild')");
    }

    /**
     * Bindings, in order: the MATCH expression from compileMatch(), the
     * organization id, the limit.
     */
    public static function compileSearch(SearchableEntity $entity): string
    {
        $table = $entity->table();
        $fts = $entity->ftsTable();

        return "SELECT b.id FROM {$table} AS b"
            ." JOIN {$fts} ON {$fts}.rowid = b.rowid"
            ." WHERE {$fts} MATCH ? AND b.organization_id = ? AND b.deleted_at IS NULL"
            .' ORDER BY b.id LIMIT ?';
    }

    /**
     * Every term is quoted, so an FTS5 keyword (AND, OR, NOT, NEAR) in the input
     * is matched as a word instead of parsed as an operator; adjacent phrases are
     * an implicit AND; `"term"*` is a prefix term.
     *
     * @param  list<string>  $terms
     */
    public static function compileMatch(array $terms): string
    {
        return implode(' ', array_map(static function (string $term): string {
            $word = str_replace('"', '""', SearchTerms::word($term));

            return SearchTerms::isPrefix($term) ? "\"{$word}\"*" : "\"{$word}\"";
        }, $terms));
    }
}
