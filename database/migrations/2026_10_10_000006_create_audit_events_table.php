<?php

use App\Support\Database\BusinessObjectColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only audit trail (§10, §11): who did what to which subject with
     * what outcome. `context` is redacted before it is written and never
     * holds blobs, screenshots or credentials (ADR-003 D7 row 8). No soft
     * delete: audit rows are only removed by the retention job.
     */
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table): void {
            BusinessObjectColumns::ulid($table);
            BusinessObjectColumns::organization($table, nullable: true);
            $table->string('actor', 200);
            $table->string('action', 100);
            $table->string('subject_type', 100)->nullable();
            $table->char('subject_id', 26)->nullable();
            $table->string('outcome', 20);
            $table->json('context')->nullable();
            $table->dateTime('occurred_at');
            BusinessObjectColumns::lineage($table);
            BusinessObjectColumns::timestamps($table, softDeletes: false);

            $table->index(['organization_id', 'occurred_at'], 'audit_events_organization_occurred_at_index');
            $table->index(['subject_type', 'subject_id'], 'audit_events_subject_index');
            $table->index(['action', 'occurred_at'], 'audit_events_action_occurred_at_index');
        });
    }
};
