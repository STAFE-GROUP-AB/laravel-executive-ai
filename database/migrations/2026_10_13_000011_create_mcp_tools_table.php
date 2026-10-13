<?php

use App\Support\Database\BusinessObjectColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §3.1 `mcp_tools`: one row per tool a server declared, **unique per
     * `(server_id, tool_name)`** — the key that makes "the same tool, changed"
     * distinguishable from "a new tool", which is the whole of ADR-005 D4.6's
     * rug-pull protection.
     *
     * `schema_hash` is `sha256(canonical tool JSON)`, pinned at Connect. On every
     * later `tools/list` the hash is recomputed: a different description, a changed
     * input schema or a new tool suspends every grant for that server until the user
     * re-reviews. A tool that disappears is stamped `removed_at` rather than deleted,
     * so a grant that referenced it still explains itself.
     *
     * `title`, `description`, `input_schema` and `annotations` are **external
     * content**: stored and rendered as data, never inserted into a system prompt and
     * never allowed to change the tool list the model sees (D4.8). `annotations`
     * (`readOnlyHint`, `destructiveHint`, `idempotentHint`, `openWorldHint`) is
     * recorded for display and **never** used for policy — the spec requires a client
     * to treat annotations as untrusted unless the server is trusted, and only T0 is.
     * `is_mutating` is our own verdict, set by review, and it is what the PolicyGate
     * reads.
     */
    public function up(): void
    {
        Schema::create('mcp_tools', function (Blueprint $table): void {
            BusinessObjectColumns::ulid($table);
            BusinessObjectColumns::organization($table);
            $table->char('server_id', 26);
            $table->string('tool_name', 200);
            $table->string('title', 200)->nullable();
            $table->text('description')->nullable();
            $table->json('input_schema');
            $table->json('output_schema')->nullable();
            $table->json('annotations')->nullable();
            $table->string('schema_hash', 71);
            $table->boolean('is_mutating')->default(true);
            $table->boolean('sends_crm_data')->default(true);
            $table->string('review_state', 30);
            $table->dateTime('first_seen_at');
            $table->dateTime('last_seen_at');
            $table->dateTime('removed_at')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->char('reviewed_by_user_id', 26)->nullable();
            BusinessObjectColumns::lineage($table);
            BusinessObjectColumns::timestamps($table);

            $table->foreign('server_id', 'mcp_tools_server_fk')->references('id')->on('mcp_servers');
            $table->foreign('reviewed_by_user_id', 'mcp_tools_reviewed_by_user_fk')->references('id')->on('users');
            $table->unique(['server_id', 'tool_name'], 'mcp_tools_server_tool_unique');
            $table->index(['organization_id', 'review_state'], 'mcp_tools_organization_review_state_index');
            $table->index(['server_id', 'schema_hash'], 'mcp_tools_server_schema_hash_index');
        });
    }
};
