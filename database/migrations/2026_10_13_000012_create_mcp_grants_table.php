<?php

use App\Support\Database\BusinessObjectColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §3.1 `mcp_grants`: permission to call **one tool**, granted by one user, after
     * review (ADR-005 D4.6 "Grant is per tool into `mcp_grants`").
     *
     * `granted_schema_hash` is copied from `mcp_tools.schema_hash` at the moment of
     * the grant, so a grant always knows what it was given against. That makes D4.6's
     * suspension a comparison rather than a recollection: when a later `tools/list`
     * changes a tool, every grant whose `granted_schema_hash` no longer matches is
     * stamped `suspended_at` with a reason, and the user re-reviews.
     *
     * Revocation is a stamp, not a delete (D4.7 deletes the *live* grant, this table
     * keeps the record): `revoked_at` plus `revoked_reason`, kept under the
     * `mcp_grant_record` retention rule, because "was this tool ever allowed to see
     * customer data" is the first question an incident asks.
     *
     * `mcp_grants_tool_active_unique` uses the same portable device as
     * `brief_deliveries`: `active_marker` is a generated column that is 1 while the
     * grant is neither revoked nor suspended and NULL otherwise, and both engines
     * treat NULLs in a unique index as distinct — so a tool may have any number of
     * historical grants and **at most one live one**. A second concurrent grant for
     * the same tool is rejected by the index rather than by a check somebody has to
     * remember to write.
     */
    public function up(): void
    {
        Schema::create('mcp_grants', function (Blueprint $table): void {
            BusinessObjectColumns::ulid($table);
            BusinessObjectColumns::organization($table);
            $table->char('server_id', 26);
            $table->char('tool_id', 26);
            $table->char('granted_by_user_id', 26);
            $table->string('granted_schema_hash', 71);
            $table->string('trust_tier', 2);
            $table->boolean('requires_action_approval')->default(true);
            $table->json('argument_constraints')->nullable();
            $table->unsignedInteger('call_budget')->nullable();
            $table->unsignedInteger('calls_made')->default(0);
            $table->dateTime('granted_at');
            $table->dateTime('reauthenticated_at')->nullable();
            $table->char('audit_event_id', 26)->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('last_used_at')->nullable();
            $table->dateTime('suspended_at')->nullable();
            $table->string('suspended_reason', 200)->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->string('revoked_reason', 200)->nullable();
            $table->unsignedTinyInteger('active_marker')->nullable()
                ->virtualAs('case when revoked_at is null and suspended_at is null then 1 else null end');
            BusinessObjectColumns::lineage($table);
            BusinessObjectColumns::timestamps($table);

            $table->foreign('server_id', 'mcp_grants_server_fk')->references('id')->on('mcp_servers');
            $table->foreign('tool_id', 'mcp_grants_tool_fk')->references('id')->on('mcp_tools');
            $table->foreign('granted_by_user_id', 'mcp_grants_granted_by_user_fk')->references('id')->on('users');
            $table->foreign('audit_event_id', 'mcp_grants_audit_event_fk')->references('id')->on('audit_events');
            $table->unique(['tool_id', 'active_marker'], 'mcp_grants_tool_active_unique');
            $table->index(['organization_id', 'server_id'], 'mcp_grants_organization_server_index');
            $table->index(['server_id', 'revoked_at'], 'mcp_grants_server_revoked_index');
        });
    }
};
