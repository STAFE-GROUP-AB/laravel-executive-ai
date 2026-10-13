<?php

use App\Support\Database\BusinessObjectColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The evidence one memory rests on (§3.1 `memory_sources`). §4.2 requires every
     * material claim to carry a source reference or an explicit uncertainty marker;
     * this table is what makes that true of memory as well as of the brief — a
     * reviewer approving a proposed memory can see exactly which communication, brief
     * or run it came from, and the owner can delete the memory when the source goes.
     *
     * `source_type` plus the §3.1 `source_id` / `source_uri` form the morph to the
     * record of origin, the same pattern `communications` uses. `communication_id` and
     * `ai_run_id` are real foreign keys because those two tables exist; the rest are
     * reached through `source_type` + `source_id` so no item type blocks on a table
     * that has not landed yet.
     *
     * `excerpt` is the quoted fragment the claim came from: external content, data and
     * never instruction, and it passes the `Redactor` on write.
     */
    public function up(): void
    {
        Schema::create('memory_sources', function (Blueprint $table): void {
            BusinessObjectColumns::ulid($table);
            BusinessObjectColumns::organization($table);

            $table->char('memory_id', 26);
            $table->string('source_type', 40);
            $table->unsignedInteger('position')->default(0);
            $table->char('communication_id', 26)->nullable();
            $table->char('ai_run_id', 26)->nullable();
            $table->text('excerpt')->nullable();
            $table->dateTime('occurred_at')->nullable();

            BusinessObjectColumns::lineage($table);
            BusinessObjectColumns::timestamps($table);

            $table->foreign('memory_id', 'memory_sources_memory_fk')->references('id')->on('memories');
            $table->foreign('communication_id', 'memory_sources_communication_fk')->references('id')->on('communications');
            $table->foreign('ai_run_id', 'memory_sources_ai_run_fk')->references('id')->on('ai_runs');

            $table->unique(['memory_id', 'position'], 'memory_sources_memory_position_unique');
            $table->index(['organization_id', 'source_type', 'source_id'], 'memory_sources_organization_source_index');
            $table->index(['communication_id'], 'memory_sources_communication_index');
            $table->index(['ai_run_id'], 'memory_sources_ai_run_index');
        });
    }
};
