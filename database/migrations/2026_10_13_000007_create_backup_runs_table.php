<?php

use App\Support\Database\BusinessObjectColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §3.1 `backup_runs` — ADR-002 **D5**: the record of one `VACUUM INTO` snapshot,
     * one restore drill, or one `lea:backend:migrate` profile migration (D10, which
     * writes a row here and refuses to run without a fresh snapshot).
     *
     * The columns are the steps D5 prescribes, so a half-finished backup is visibly
     * half-finished rather than indistinguishable from a good one:
     * `integrity_check` is the `PRAGMA integrity_check` verdict on the **copy**,
     * `renamed_at` the atomic rename that follows it, `encrypted_at` the handover to
     * the ADR-003 encryption step, and `checksum` the `sha256:` over the finished
     * artefact. A row with `status = ok` and a null `integrity_check` is a bug the
     * schema makes visible.
     *
     * `snapshot_path` is a path, never the data, and `row_counts` is the per-table
     * count D10's migration verifies on both sides. Append-only: no soft delete, and
     * the `backup_log` retention rule gives it a year.
     *
     * `organization_id` is nullable because a snapshot covers the whole database
     * file, not one workspace — the same reason `audit_events` allows it for system
     * events.
     */
    public function up(): void
    {
        Schema::create('backup_runs', function (Blueprint $table): void {
            BusinessObjectColumns::ulid($table);
            BusinessObjectColumns::organization($table, nullable: true);
            $table->string('kind', 30);
            $table->string('status', 20);
            $table->string('trigger', 30);
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('snapshot_path', 2000)->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
            $table->string('integrity_check', 20)->nullable();
            $table->dateTime('renamed_at')->nullable();
            $table->dateTime('encrypted_at')->nullable();
            $table->string('checksum', 71)->nullable();
            $table->json('row_counts')->nullable();
            $table->unsignedInteger('wal_pages_checkpointed')->nullable();
            $table->dateTime('retained_until')->nullable();
            $table->dateTime('pruned_at')->nullable();
            $table->string('error', 500)->nullable();
            BusinessObjectColumns::lineage($table);
            BusinessObjectColumns::timestamps($table, softDeletes: false);

            $table->index(['kind', 'started_at'], 'backup_runs_kind_started_at_index');
            $table->index(['status', 'started_at'], 'backup_runs_status_started_at_index');
            $table->index('retained_until', 'backup_runs_retained_until_index');
        });
    }
};
