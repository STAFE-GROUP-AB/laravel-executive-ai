<?php

use App\Support\Database\BusinessObjectColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One AI run and its provenance (§3.1 `ai_runs`, ADR-004 D7). Append-only in
     * spirit — a row is inserted when the run starts and closed when it ends — so no
     * soft delete; the retention job is the only thing that removes a row.
     *
     * **ADR-004 D7's thirteen provenance fields** are the point of the table:
     * provider, model id, model digest, quantization, privacy mode, region evidence
     * id, prompt hash, capabilities snapshot, usage, cost, wall time, outcome, plus
     * the ULID `trace_id` that is propagated to `tool_runs` and MCP calls and is
     * deliberately **never** sent as a header to an external provider. The mapping
     * from field to column lives in `App\AI\Runs\RunProvenanceField` so the contract
     * test walks it instead of trusting prose.
     *
     * `provider` and `model_id` are copied as strings next to the foreign keys: a run
     * must stay readable after the owner deletes the provider row, and a run that
     * reached a model must record which one even when `ai_models` has moved on.
     *
     * **The dedupe key** is ADR-004 D7's `sha256(job_class, normalized_input,
     * model_id)`, and the index is what makes a duplicate *unrecordable* rather than
     * merely detectable (the argument ADR-009 D1.2 makes for `brief_deliveries`).
     * Two columns carry the window the key is valid in:
     *
     *  - `dedupe_window_start` is the UTC start of the `dedupe_window_minutes` bucket
     *    the run falls in, so a request repeated an hour later lands in a new bucket
     *    and inserts normally instead of colliding forever;
     *  - `dedupe_marker` is a generated column that is `1` while the run holds the
     *    slot (`running` or `succeeded`) and `NULL` otherwise. Both engines treat
     *    `NULL` as distinct in a unique index, so any number of failed, refused or
     *    cancelled runs may coexist with at most one live run per key and window.
     *    This is the portable form of a partial unique index — MySQL has none — and
     *    the same idiom as `brief_deliveries_single_sent_unique`.
     *
     * Token columns are explicit rather than a JSON blob because the €5.00-per-day
     * and €0.50-per-run caps are `SUM()`ed over them per provider (D7); `cost_eur` is
     * `decimal(12,6)` so the sum carries no float drift, and is `0` for a local model,
     * which still records its tokens.
     *
     * `prompt_redacted` and `response_redacted` pass `App\Security\Secrets\Redactor`
     * on write (ADR-003 D7 row 4). They are text, never a blob and never a path.
     */
    public function up(): void
    {
        Schema::create('ai_runs', function (Blueprint $table): void {
            BusinessObjectColumns::ulid($table);
            BusinessObjectColumns::organization($table);

            // D7 field 13: one ULID per run, propagated to tool runs and MCP calls.
            $table->char('trace_id', 26);
            $table->string('job_class', 200);

            // D7 fields 1-2: provider and model, as ids and as copied names.
            $table->char('ai_provider_id', 26)->nullable();
            $table->string('provider', 40);
            $table->char('ai_model_id', 26)->nullable();
            $table->string('model_id', 200);

            // D7 fields 3-4: what exactly answered.
            $table->string('model_digest', 100)->nullable();
            $table->string('quantization', 50)->nullable();

            // D7 fields 5-6: the privacy mode in force and the evidence row it relied on.
            $table->string('privacy_mode', 20);
            $table->char('region_evidence_id', 26)->nullable();

            // D7 fields 7-8: the prompt hash and the capability snapshot used to route.
            $table->string('prompt_hash', 71);
            $table->json('capabilities_snapshot');

            // D7 field 9: usage. Explicit columns so the budget caps can sum them.
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->unsignedBigInteger('cache_read_input_tokens')->nullable();
            $table->unsignedBigInteger('cache_write_input_tokens')->nullable();
            $table->unsignedBigInteger('reasoning_tokens')->nullable();
            $table->unsignedBigInteger('total_tokens')->default(0);

            // D7 field 10: cost in EUR; 0 for a local model, null when unpriced.
            $table->decimal('cost_eur', 12, 6)->nullable();
            $table->decimal('max_cost_eur', 12, 6)->nullable();

            // D7 field 11: wall time.
            $table->unsignedBigInteger('wall_time_ms')->nullable();

            // The rest of the D7 control plane as it was actually applied.
            $table->unsignedSmallInteger('max_steps')->nullable();
            $table->unsignedSmallInteger('steps_used')->default(0);
            $table->unsignedInteger('max_time_seconds')->nullable();
            $table->unsignedInteger('max_output_tokens')->nullable();

            $table->text('prompt_redacted')->nullable();
            $table->text('response_redacted')->nullable();

            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancellation_source', 40)->nullable();
            $table->string('finish_reason', 20)->nullable();
            $table->string('error_kind', 40)->nullable();
            $table->string('error_message', 500)->nullable();

            // D7 field 12: the outcome vocabulary, `cancelled` included.
            $table->string('outcome', 20);

            // D7 dedupe: the key, the window it is valid in, and the slot marker.
            $table->string('dedupe_key', 71);
            $table->dateTime('dedupe_window_start');
            $table->unsignedTinyInteger('dedupe_marker')->nullable()
                ->virtualAs("case when outcome in ('running', 'succeeded') then 1 else null end");

            BusinessObjectColumns::lineage($table);
            BusinessObjectColumns::timestamps($table, softDeletes: false);

            $table->foreign('ai_provider_id', 'ai_runs_provider_fk')->references('id')->on('ai_providers');
            $table->foreign('ai_model_id', 'ai_runs_model_fk')->references('id')->on('ai_models');
            $table->foreign('region_evidence_id', 'ai_runs_region_evidence_fk')->references('id')->on('ai_providers');

            $table->unique(
                ['organization_id', 'dedupe_key', 'dedupe_window_start', 'dedupe_marker'],
                'ai_runs_dedupe_window_unique'
            );
            $table->index(['trace_id'], 'ai_runs_trace_id_index');
            $table->index(['organization_id', 'started_at'], 'ai_runs_organization_started_at_index');
            $table->index(['organization_id', 'provider', 'started_at'], 'ai_runs_organization_provider_started_at_index');
            $table->index(['organization_id', 'job_class', 'started_at'], 'ai_runs_organization_job_started_at_index');
            $table->index(['organization_id', 'outcome', 'started_at'], 'ai_runs_organization_outcome_index');
        });
    }
};
