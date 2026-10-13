<?php

declare(strict_types=1);

namespace App\Support\Database;

use App\Support\Data\SensitivityClass;
use Illuminate\Database\Schema\Blueprint;

/**
 * Blueprint §3.1 common columns for every business object: ULID primary key,
 * organization_id, timestamps, retention policy, source_id/source_uri,
 * sensitivity class, provenance, soft delete. Added by every business
 * migration so the forty-table retrofit never happens (issue #29).
 *
 * Portable per ADR-002 D10: ULIDs are char(26), JSON via json(), timestamps
 * are UTC datetime, no enum columns (string + PHP enum), named indexes.
 */
final class BusinessObjectColumns
{
    public static function ulid(Blueprint $table, string $column = 'id'): void
    {
        $table->char($column, 26);

        if ($column === 'id') {
            $table->primary('id', "{$table->getTable()}_pk");
        }
    }

    public static function organization(Blueprint $table, bool $nullable = false): void
    {
        $column = $table->char('organization_id', 26);

        if ($nullable) {
            $column->nullable();
        }

        $table->foreign('organization_id', "{$table->getTable()}_organization_fk")
            ->references('id')
            ->on('organizations');
    }

    /**
     * Retention, source reference, sensitivity class and provenance (§3.1).
     * `provenance` follows the vocabulary in packaging/fixtures/crm/crm.schema.json
     * (origin, actor, recorded_at, trace_id) and never carries credentials.
     *
     * `$sensitivityClass` is the column *default*, not a constraint: a table whose
     * every row is personal data (`email_connections`, `contacts`) or a shared
     * secret (`push_subscriptions`) declares that here so a row inserted without
     * the attribute is classified correctly rather than as `internal` (S2-03).
     */
    public static function lineage(Blueprint $table, SensitivityClass $sensitivityClass = SensitivityClass::Internal): void
    {
        $table->string('source_id', 200)->nullable();
        $table->string('source_uri', 2000)->nullable();
        $table->string('sensitivity_class', 20)->default($sensitivityClass->value);
        $table->json('provenance');
        $table->string('retention_policy', 64)->nullable();
        $table->dateTime('retain_until')->nullable();
    }

    public static function timestamps(Blueprint $table, bool $softDeletes = true): void
    {
        $table->dateTime('created_at')->nullable();
        $table->dateTime('updated_at')->nullable();

        if ($softDeletes) {
            $table->dateTime('deleted_at')->nullable();
        }
    }
}
