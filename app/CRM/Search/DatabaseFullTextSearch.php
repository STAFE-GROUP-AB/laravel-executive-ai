<?php

declare(strict_types=1);

namespace App\CRM\Search;

use App\Support\Database\MysqlFullTextGrammar;
use App\Support\Database\SqliteFullTextGrammar;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

/**
 * The one implementation of FullTextSearch: picks the grammar for the default
 * connection's driver (ADR-002 D1 profiles) and runs its statement. Raw SQL
 * lives in the grammar classes only (ADR-002 D10).
 */
final class DatabaseFullTextSearch implements FullTextSearch
{
    public function __construct(private readonly DatabaseManager $connections) {}

    public function search(SearchableEntity $entity, string $organizationId, string $query, int $limit = 50): array
    {
        $terms = SearchTerms::parse($query);

        if ($terms === [] || $limit < 1) {
            return [];
        }

        $connection = $this->connections->connection();
        $driver = $connection->getDriverName();

        [$sql, $match] = match ($driver) {
            'sqlite' => [SqliteFullTextGrammar::compileSearch($entity), SqliteFullTextGrammar::compileMatch($terms)],
            'mysql', 'mariadb' => [MysqlFullTextGrammar::compileSearch($entity), MysqlFullTextGrammar::compileMatch($terms)],
            default => throw new RuntimeException("Full-text search has no grammar for the `{$driver}` driver (ADR-002 D4)."),
        };

        $rows = $connection->select($sql, [$match, $organizationId, $limit]);

        return array_values(array_map(static fn (object $row): string => (string) $row->id, $rows));
    }
}
