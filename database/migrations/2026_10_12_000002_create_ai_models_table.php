<?php

use App\Support\Database\BusinessObjectColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One model the owner has configured under a provider (§3.1 `ai_models`; it *is*
     * ADR-007 D6's "ai_model_register"). Two jobs:
     *
     *  1. **The persisted half of `ModelCapabilities`** (ADR-004 D3). The SDK carries
     *     no per-model capability data, so this is our own registry: the booleans and
     *     bounds mirror `App\AI\Providers\ModelCapabilities` one-to-one, and
     *     `capability_source` records which of D3's three sources the row came from
     *     (`resources/ai/models.json`, a runtime probe, or a user override marked
     *     "unverified — may fail"). A model nobody knows gets the fail-closed default
     *     from `ModelCapabilities::unknown()`; the router refuses, it never degrades.
     *  2. **ADR-007 D6 R6 and R7**: the model licence (name, URL, text hash,
     *     acceptance record) and the provenance triple (model id, version, digest,
     *     quantisation) that every `ai_runs` row copies.
     *
     * Prices are per million tokens in EUR, `decimal(12,6)` so a tenth of a cent per
     * MTok is exact and the €5.00-per-day cap sums without float drift. `null` means
     * unknown; `0` is a local model, which still records tokens (ADR-004 D7).
     */
    public function up(): void
    {
        Schema::create('ai_models', function (Blueprint $table): void {
            BusinessObjectColumns::ulid($table);
            BusinessObjectColumns::organization($table);

            $table->char('ai_provider_id', 26);
            $table->string('model_id', 200);
            $table->string('display_name', 200);
            $table->boolean('enabled')->default(false);

            // ADR-007 D6 R7: provenance, copied into every run's model_provenance.
            $table->string('model_version', 100)->nullable();
            $table->string('model_digest', 100)->nullable();
            $table->string('quantization', 50)->nullable();
            $table->string('parameter_size', 50)->nullable();

            // ADR-004 D3: the capability record, fail-closed by default.
            $table->boolean('text')->default(true);
            $table->boolean('streaming')->default(true);
            $table->boolean('tools')->default(false);
            $table->boolean('structured_output')->default(false);
            $table->boolean('vision')->default(false);
            $table->boolean('embeddings')->default(false);
            $table->unsignedInteger('embedding_dimensions')->nullable();
            $table->unsignedInteger('context_window')->default(4096);
            $table->unsignedInteger('max_output_tokens')->nullable();
            $table->decimal('input_price_per_mtok', 12, 6)->nullable();
            $table->decimal('output_price_per_mtok', 12, 6)->nullable();
            $table->unsignedSmallInteger('max_concurrency')->default(1);
            $table->string('capability_source', 20)->default('unknown');
            $table->string('capability_source_url', 1000)->nullable();
            $table->dateTime('capabilities_verified_at')->nullable();

            // ADR-007 D6 R6: the model licence and the owner's acceptance of it.
            $table->string('license_name', 200)->nullable();
            $table->string('license_url', 1000)->nullable();
            $table->string('license_text_hash', 71)->nullable();
            $table->dateTime('license_accepted_at')->nullable();
            $table->char('license_accepted_by_user_id', 26)->nullable();

            BusinessObjectColumns::lineage($table);
            BusinessObjectColumns::timestamps($table);

            $table->foreign('ai_provider_id', 'ai_models_provider_fk')->references('id')->on('ai_providers');
            $table->foreign('license_accepted_by_user_id', 'ai_models_license_accepted_by_user_fk')->references('id')->on('users');

            $table->unique(['ai_provider_id', 'model_id'], 'ai_models_provider_model_unique');
            $table->index(['organization_id', 'enabled'], 'ai_models_organization_enabled_index');
            $table->index(['organization_id', 'capability_source'], 'ai_models_organization_capability_source_index');
            $table->index(['ai_provider_id', 'enabled'], 'ai_models_provider_enabled_index');
        });
    }
};
