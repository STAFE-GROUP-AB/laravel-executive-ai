<?php

use App\Support\Database\BusinessObjectColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One tool invocation inside an AI run (§3.1 `tool_runs`). ADR-004 names this
     * table only in prose (D7, "`trace_id` propagated to tool runs"); S2-02 specifies
     * it, because the §1.2 `/assistant` tool-activity surface has nowhere else to read
     * from. Append-only: no soft delete.
     *
     * **`policy_decision` is not nullable on purpose.** Blueprint §4: the LLM
     * proposes, a server-side `PolicyGate` decides. A tool invocation that was never
     * put to the gate therefore has no representable row — a denial is recorded with
     * outcome `denied` and produces a tool result the model can read, and nothing
     * executed (ADR-004 D2 row 3).
     *
     * **No binary column and no image path** (ADR-003 D7 row 8, ADR-005 D1.7). A
     * computer-use row stores `screenshot_sha256` plus the frame's dimensions and the
     * app bundle id and window title — a hash for correlation, nothing that could
     * reconstruct or locate the image. `tests/Security/AuditNoBlobTest.php` asserts
     * this for `tool_runs` and `audit_events` on both profiles, so a later migration
     * cannot quietly add a blob or a path.
     *
     * `arguments_redacted` and `result_redacted` pass
     * `App\Security\Secrets\Redactor` on write (ADR-003 D7 row 4); tools take a
     * `connection_id`, never a credential, so nothing in them should match the
     * corpus in the first place — the redactor is the second line.
     *
     * `mcp_server_id` has no foreign key: the MCP registry lands with S2-03, the same
     * way `communications.account_id` waits for `email_connections` (ADR-002 D9.2).
     */
    public function up(): void
    {
        Schema::create('tool_runs', function (Blueprint $table): void {
            BusinessObjectColumns::ulid($table);
            BusinessObjectColumns::organization($table);

            $table->char('ai_run_id', 26)->nullable();
            $table->char('trace_id', 26);
            $table->string('tool_name', 200);
            $table->string('tool_kind', 20);
            $table->char('mcp_server_id', 26)->nullable();
            $table->unsignedSmallInteger('step')->default(0);

            $table->json('arguments_redacted')->nullable();
            $table->json('result_redacted')->nullable();

            // §4: the server-side gate's decision, always recorded.
            $table->string('policy_decision', 20);
            $table->string('policy_reason', 200)->nullable();
            $table->char('action_proposal_id', 26)->nullable();

            $table->string('outcome', 20);
            $table->string('error_kind', 40)->nullable();
            $table->string('error_message', 500)->nullable();

            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();

            // ADR-005 D1.7 / D1.10: hash, dimensions, app and window only.
            $table->string('app_bundle_id', 200)->nullable();
            $table->string('window_title', 500)->nullable();
            $table->string('screenshot_sha256', 71)->nullable();
            $table->unsignedInteger('screenshot_width')->nullable();
            $table->unsignedInteger('screenshot_height')->nullable();

            BusinessObjectColumns::lineage($table);
            BusinessObjectColumns::timestamps($table, softDeletes: false);

            $table->foreign('ai_run_id', 'tool_runs_ai_run_fk')->references('id')->on('ai_runs');
            $table->foreign('action_proposal_id', 'tool_runs_action_proposal_fk')->references('id')->on('action_proposals');

            $table->index(['trace_id'], 'tool_runs_trace_id_index');
            $table->index(['ai_run_id', 'step'], 'tool_runs_ai_run_step_index');
            $table->index(['organization_id', 'started_at'], 'tool_runs_organization_started_at_index');
            $table->index(['organization_id', 'tool_name', 'started_at'], 'tool_runs_organization_tool_started_at_index');
            $table->index(['organization_id', 'policy_decision', 'started_at'], 'tool_runs_organization_decision_index');
        });
    }
};
