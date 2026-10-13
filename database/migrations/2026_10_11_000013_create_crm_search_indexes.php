<?php

use App\CRM\Search\SearchableEntity;
use App\Support\Database\MysqlFullTextGrammar;
use App\Support\Database\SqliteFullTextGrammar;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-002 D4: one external-content FTS5 table per searchable entity
     * (`customers_fts`, `contacts_fts`, `communications_fts`) kept in step by
     * AFTER INSERT/UPDATE/DELETE triggers on the `local` profile; InnoDB FULLTEXT
     * indexes with the same columns on the `server` profile. Which columns are
     * indexed is decided once, in SearchableEntity, so both profiles agree.
     */
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        foreach (SearchableEntity::cases() as $entity) {
            if ($driver === 'sqlite') {
                SqliteFullTextGrammar::createIndex(Schema::getConnection(), $entity);

                continue;
            }

            Schema::table($entity->table(), function (Blueprint $table) use ($entity): void {
                $table->fullText($entity->columns(), MysqlFullTextGrammar::indexName($entity));
            });
        }
    }
};
