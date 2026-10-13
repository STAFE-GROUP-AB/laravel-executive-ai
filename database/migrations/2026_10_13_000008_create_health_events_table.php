<?php

use App\Support\Database\BusinessObjectColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §3.1 `health_events`: the durable half of the health model. The §12.2 checks
     * answer "is it healthy now" from cache heartbeats; this table answers "has it
     * been", which is what the ADR-002 reopen conditions are written against:
     *
     *  - **D3.6** — a queue job that still gets `database is locked` after the 5 s
     *    `busy_timeout` records the lock wait here, and the reopen condition counts
     *    it (`> 0.1 % of jobs over any 7-day window`).
     *  - **D6** — the nightly `PRAGMA wal_checkpoint(TRUNCATE)` records WAL size and
     *    the checkpoint result here; a WAL above 256 MB or pages it could not
     *    checkpoint on two consecutive nights is a warning, and "two consecutive
     *    nights" is only answerable from a log.
     *
     * `context` is redacted by `App\Security\Secrets\Redactor` before insert, the
     * same rule as `audit_events`, and carries no customer content: a health event
     * says a job was slow, never what it was about. Append-only, no soft delete;
     * ninety days under the `health_telemetry` rule.
     *
     * `organization_id` is nullable — a checkpoint or a locked database is a property
     * of the install, not of a workspace.
     */
    public function up(): void
    {
        Schema::create('health_events', function (Blueprint $table): void {
            BusinessObjectColumns::ulid($table);
            BusinessObjectColumns::organization($table, nullable: true);
            $table->string('name', 60);
            $table->string('category', 30);
            $table->string('status', 20);
            $table->string('detail', 500)->nullable();
            $table->json('context')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->dateTime('occurred_at');
            BusinessObjectColumns::lineage($table);
            BusinessObjectColumns::timestamps($table, softDeletes: false);

            $table->index(['name', 'occurred_at'], 'health_events_name_occurred_at_index');
            $table->index(['status', 'occurred_at'], 'health_events_status_occurred_at_index');
            $table->index(['category', 'occurred_at'], 'health_events_category_occurred_at_index');
        });
    }
};
