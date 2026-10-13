<?php

use App\Support\Database\BusinessObjectColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One configured AI provider account (§3.1 `ai_providers`; it *is* ADR-007 D6's
     * "ai_provider_register"). Three rules shape the columns:
     *
     *  1. **No credential, ever** (ADR-003 D1). The row carries only
     *     `secret_service` / `secret_account` / `secret_version` — the coordinates
     *     of a Keychain generic-password item — and never a key, token, password or
     *     verifier, encrypted or not. There is deliberately no `headers` column:
     *     the SDK's `openai-compatible` driver accepts arbitrary headers, which is
     *     exactly where a bearer token would end up in the database.
     *  2. **ADR-004 D5's eight region, DPA and retention evidence fields** decide
     *     `EU_RESTRICTED` eligibility; the mapping from field to column lives in
     *     `App\AI\Compliance\RegionEvidenceField` so the contract test can walk it.
     *     Missing or expired evidence means the router refuses and names what is
     *     missing; region is never inferred from a hostname.
     *  3. **ADR-007 D6's R1-R7 register rows** are the admin UI's columns;
     *     `App\AI\Compliance\ProviderRegisterField` maps each to its columns. R1-R6
     *     are provider-level and live here; R7 (model id, version, digest,
     *     quantisation) is per model and lives on `ai_models` and `ai_runs`.
     *
     * Evidence URLs are `string(1000)`, not the §3.1 `source_uri` width: eight
     * varchar(2000) columns on one utf8mb4 table would approach MySQL's 65 535-byte
     * row definition limit, and no vendor evidence page needs more.
     */
    public function up(): void
    {
        Schema::create('ai_providers', function (Blueprint $table): void {
            BusinessObjectColumns::ulid($table);
            BusinessObjectColumns::organization($table);

            $table->string('provider_id', 40);
            $table->string('display_name', 200);
            $table->string('kind', 20);
            $table->string('driver', 40);
            $table->string('base_url', 1000)->nullable();
            $table->boolean('enabled')->default(false);
            $table->string('disabled_reason', 200)->nullable();
            $table->dateTime('last_used_at')->nullable();

            // ADR-003 D1: Keychain coordinates only. Never a secret value.
            $table->string('secret_service', 200)->nullable();
            $table->string('secret_account', 200)->nullable();
            $table->unsignedInteger('secret_version')->nullable();

            // ADR-004 D5, field 1-2: the contracted region and how it is bound.
            // `region_mechanism` is a JSON list because OpenAI binds by endpoint
            // *and* by project setting (D5 "Verified vendor state").
            $table->string('processing_region', 20)->default('UNKNOWN');
            $table->json('region_mechanism');

            // ADR-004 D5, field 3 / ADR-007 D6 R1: the document relied on, and when.
            $table->string('region_evidence_url', 1000)->nullable();
            $table->dateTime('region_verified_at')->nullable();

            // ADR-004 D5, field 4: the DPA actually in force for this account.
            $table->string('dpa_reference', 200)->nullable();
            $table->dateTime('dpa_signed_at')->nullable();

            // ADR-004 D5, field 5 / ADR-007 D6 R2: sub-processors, re-checked every 90 days.
            $table->string('subprocessors_url', 1000)->nullable();
            $table->dateTime('subprocessors_checked_at')->nullable();

            // ADR-004 D5, field 6: pointer to the §10.6 transfer impact analysis.
            $table->string('transfer_impact_ref', 200)->nullable();

            // ADR-004 D5, field 7 / ADR-007 D6 R5: zero data retention and the
            // provider's stated prompt/output retention.
            $table->boolean('zero_data_retention')->default(false);
            $table->string('zero_data_retention_evidence_url', 1000)->nullable();
            $table->dateTime('zero_data_retention_verified_at')->nullable();
            $table->string('retention_statement', 500)->nullable();
            $table->string('retention_evidence_url', 1000)->nullable();
            $table->dateTime('retention_verified_at')->nullable();

            // ADR-004 D5, field 8: who confirmed it; eligibility lapses on expiry.
            $table->char('verified_by_user_id', 26)->nullable();
            $table->dateTime('evidence_expires_at')->nullable();

            // ADR-007 D6 R3 and R4: the DPA link and status, and the TIA status.
            $table->string('dpa_url', 1000)->nullable();
            $table->string('dpa_status', 20)->default('not_reviewed');
            $table->string('tia_status', 20)->default('pending');

            // ADR-007 D6 R6: the provider's own terms, hashed and accepted.
            $table->string('license_name', 200)->nullable();
            $table->string('license_url', 1000)->nullable();
            $table->string('license_text_hash', 71)->nullable();
            $table->dateTime('license_accepted_at')->nullable();
            $table->char('license_accepted_by_user_id', 26)->nullable();

            BusinessObjectColumns::lineage($table);
            BusinessObjectColumns::timestamps($table);

            $table->foreign('verified_by_user_id', 'ai_providers_verified_by_user_fk')->references('id')->on('users');
            $table->foreign('license_accepted_by_user_id', 'ai_providers_license_accepted_by_user_fk')->references('id')->on('users');

            // One label per provider per workspace, and one Keychain item per row.
            $table->unique(['organization_id', 'provider_id', 'display_name'], 'ai_providers_organization_label_unique');
            $table->unique(['secret_service', 'secret_account'], 'ai_providers_secret_unique');
            $table->index(['organization_id', 'enabled'], 'ai_providers_organization_enabled_index');
            $table->index(['organization_id', 'processing_region'], 'ai_providers_organization_region_index');
            $table->index(['organization_id', 'evidence_expires_at'], 'ai_providers_organization_evidence_expiry_index');
        });
    }
};
