<?php

use App\Support\Database\BusinessObjectColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §3.1 `mcp_servers`, with the two columns ADR-005 names explicitly:
     *
     *  - **`trust_tier`** (D4.4) — `T0` our own `laravel/mcp` tools, `T1` an
     *    allowlisted local stdio server, `T2` a remote Streamable HTTP server on a
     *    user-approved host, `T3` everything else and blocked. The tier is *decided
     *    in code, never by the model*, and it decides the default tool policy. Tool
     *    annotations (`readOnlyHint`, `destructiveHint`, …) are recorded in
     *    `mcp_tools` and never used for policy, because the spec says a client must
     *    treat them as untrusted unless the server is trusted, and only T0 is.
     *  - **`version`** (D4.6) — pinned at Connect. Together with
     *    `mcp_tools.schema_hash` and `tools_list_hash` here (the sha256 over the
     *    canonical `tools/list` response as a whole), this is what E3-5's rug-pull
     *    protection compares against: a later `tools/list` that differs in a
     *    description, a schema or a new tool suspends every grant for the server
     *    until the user re-reviews. Those columns land now, with the schema, because
     *    a comparison has nothing to compare against otherwise.
     *
     * `executable_sha256`, `declared_egress` and `declared_fs_paths` are the
     * allowlist manifest's fields for a local stdio server (D4.5); the SHA-256 is
     * verified before every spawn. `command`/`args` are stored as an absolute path
     * and an argument **array**, never a shell string ("no arbitrary shell", §11).
     *
     * Credentials follow ADR-003 D1: `secret_service`/`secret_account`/`secret_version`
     * reference the Keychain item `se.stafe.executive-ai.mcp` / `<this row's ULID>`;
     * no bearer token or OAuth token is stored here.
     */
    public function up(): void
    {
        Schema::create('mcp_servers', function (Blueprint $table): void {
            BusinessObjectColumns::ulid($table);
            BusinessObjectColumns::organization($table);
            $table->string('name', 200);
            $table->string('display_name', 200);
            $table->string('trust_tier', 2);
            $table->string('version', 100)->nullable();
            $table->string('transport', 30);
            $table->string('status', 30);
            $table->string('protocol_version', 40)->nullable();
            $table->string('endpoint_url', 2000)->nullable();
            $table->string('command', 2000)->nullable();
            $table->json('args')->nullable();
            $table->string('executable_sha256', 71)->nullable();
            $table->string('registry_source', 2000)->nullable();
            $table->string('namespace_verification', 40)->nullable();
            $table->json('declared_egress')->nullable();
            $table->json('declared_fs_paths')->nullable();
            $table->json('server_capabilities')->nullable();
            $table->string('tools_list_hash', 71)->nullable();
            $table->dateTime('tools_listed_at')->nullable();
            $table->string('secret_service', 200)->nullable();
            $table->string('secret_account', 200)->nullable();
            $table->unsignedInteger('secret_version')->nullable();
            $table->char('approved_by_user_id', 26)->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->string('reviewed_by', 200)->nullable();
            $table->dateTime('connected_at')->nullable();
            $table->dateTime('last_connected_at')->nullable();
            $table->dateTime('suspended_at')->nullable();
            $table->string('suspended_reason', 200)->nullable();
            $table->dateTime('revoked_at')->nullable();
            BusinessObjectColumns::lineage($table);
            BusinessObjectColumns::timestamps($table);

            $table->foreign('approved_by_user_id', 'mcp_servers_approved_by_user_fk')->references('id')->on('users');
            $table->unique(['organization_id', 'name'], 'mcp_servers_organization_name_unique');
            $table->index(['organization_id', 'status'], 'mcp_servers_organization_status_index');
            $table->index(['organization_id', 'trust_tier'], 'mcp_servers_organization_trust_tier_index');
        });
    }
};
