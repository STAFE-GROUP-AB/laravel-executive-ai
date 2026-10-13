<?php

use App\Support\Data\SensitivityClass;
use App\Support\Database\BusinessObjectColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §3.1 `external_accounts`: one connected **non-mail** provider account (GitHub
     * today; §3.1's "provider/account/external_id" family tomorrow). `email_connections`
     * is the mail equivalent and deliberately a separate table, because its contract
     * (`EmailConnector`, §5.6) is much wider than "an account we read items from".
     *
     * The row holds a **Keychain reference only** (ADR-003 D1: service
     * `se.stafe.executive-ai.github`, account = this row's ULID): no token, refresh
     * token, installation secret or password, encrypted or not. `scopes` is what the
     * provider actually granted, so the UI can show it and the PolicyGate can refuse
     * what is absent.
     */
    public function up(): void
    {
        Schema::create('external_accounts', function (Blueprint $table): void {
            BusinessObjectColumns::ulid($table);
            BusinessObjectColumns::organization($table);
            $table->char('user_id', 26);
            $table->string('provider', 40);
            $table->string('provider_account_id', 200);
            $table->string('login', 200)->nullable();
            $table->string('display_label', 200);
            $table->string('authorization_method', 40);
            $table->json('scopes');
            $table->json('capabilities');
            $table->string('status', 30);
            $table->string('health_state', 30);
            $table->string('health_detail', 500)->nullable();
            $table->string('secret_service', 200);
            $table->string('secret_account', 200);
            $table->unsignedInteger('secret_version')->default(1);
            $table->dateTime('connected_at');
            $table->dateTime('last_verified_at')->nullable();
            $table->dateTime('last_synced_at')->nullable();
            $table->dateTime('reauthorization_required_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->json('rate_limit')->nullable();
            BusinessObjectColumns::lineage($table, SensitivityClass::Personal);
            BusinessObjectColumns::timestamps($table);

            $table->foreign('user_id', 'external_accounts_user_fk')->references('id')->on('users');
            $table->unique(['organization_id', 'provider', 'provider_account_id'], 'external_accounts_provider_account_unique');
            $table->index(['organization_id', 'status'], 'external_accounts_organization_status_index');
            $table->index(['user_id', 'provider'], 'external_accounts_user_provider_index');
        });
    }
};
