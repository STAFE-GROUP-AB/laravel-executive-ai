<?php

use App\Support\Data\SensitivityClass;
use App\Support\Database\BusinessObjectColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §5.6 `email_connections` — **this name, never `connected_email_accounts`**
     * (ADR-002 **D8**, which resolved ambiguity A4: §5.6's security paragraph names
     * the same table). It lands empty here so E3-1's eight provider adapters write
     * into a schema that already exists.
     *
     * The columns are the persisted form of `App\Integrations\Mail\Dto\EmailConnection`
     * (module-contracts §2), including its invariants:
     *  - `provider` is `MailProviderKind` and `authorization_method`
     *    `AuthorizationMethod`; the DTO refuses a combination §5.6 does not allow,
     *    so no adapter can quietly fall back to a password prompt for Google.
     *  - `granted_scopes` is what the provider granted, `capabilities` what that
     *    lets the connector do (`MailCapability`), `send_as_addresses` the
     *    identities it may send as — an outgoing mail whose From is not among them
     *    is refused ("en agent får aldrig byta From-identitet").
     *  - `read_only` is the §5.6 ground state and `send_enabled` the separately
     *    granted exception; neither is inferred from the scope list alone.
     *  - `server_settings` is the IMAP/SMTP host, port and TLS mode
     *    (`MailServerSettings`) — hosts and ports only, **never** a password.
     *
     * **Credential handling (ADR-003 D1):** the row carries `secret_service`,
     * `secret_account` and `secret_version` — a reference to the Keychain item
     * `se.stafe.executive-ai.email` / `<this row's ULID>`. No column here or
     * anywhere else may hold a token, refresh token, app-specific password or PKCE
     * verifier, encrypted or not.
     *
     * This table is the policy boundary of §5.6 criterion 4: every connector call
     * takes exactly one connection, so cross-account access is a policy check on one
     * id rather than a query-by-query audit.
     */
    public function up(): void
    {
        Schema::create('email_connections', function (Blueprint $table): void {
            BusinessObjectColumns::ulid($table);
            BusinessObjectColumns::organization($table);
            $table->char('user_id', 26);
            $table->string('provider', 40);
            $table->string('authorization_method', 40);
            $table->string('email_address', 320);
            $table->string('display_name', 200)->nullable();
            $table->string('account_label', 200);
            $table->json('granted_scopes');
            $table->json('capabilities');
            $table->json('send_as_addresses');
            $table->json('synced_folder_ids');
            $table->json('server_settings')->nullable();
            $table->boolean('read_only')->default(true);
            $table->boolean('send_enabled')->default(false);
            $table->string('status', 30);
            $table->string('health_state', 30);
            $table->string('health_detail', 500)->nullable();
            $table->unsignedInteger('sync_interval_minutes')->default(15);
            $table->json('rate_limit')->nullable();
            $table->string('secret_service', 200)->default('se.stafe.executive-ai.email');
            $table->string('secret_account', 200);
            $table->unsignedInteger('secret_version')->default(1);
            $table->dateTime('connected_at');
            $table->dateTime('last_verified_at')->nullable();
            $table->dateTime('last_synced_at')->nullable();
            $table->dateTime('reauthorization_required_at')->nullable();
            $table->dateTime('disconnected_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            BusinessObjectColumns::lineage($table, SensitivityClass::Personal);
            BusinessObjectColumns::timestamps($table);

            $table->foreign('user_id', 'email_connections_user_fk')->references('id')->on('users');
            $table->unique(['organization_id', 'provider', 'email_address'], 'email_connections_provider_address_unique');
            $table->index(['organization_id', 'status'], 'email_connections_organization_status_index');
            $table->index(['user_id', 'health_state'], 'email_connections_user_health_index');
        });
    }
};
