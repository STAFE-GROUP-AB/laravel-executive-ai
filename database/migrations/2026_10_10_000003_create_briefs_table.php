<?php

use App\Support\Database\BusinessObjectColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per frozen version of an Executive Brief (brief schema 1.0.0,
     * docs/architecture/brief-schema.md). `id` identifies the version row;
     * `document_id` is the brief's stable identity across versions (the
     * document's `id`). `idempotency_key` is assigned at freeze and is what
     * brief_deliveries uses to make a double send detectable (§1.3).
     */
    public function up(): void
    {
        Schema::create('briefs', function (Blueprint $table): void {
            BusinessObjectColumns::ulid($table);
            BusinessObjectColumns::organization($table);
            $table->char('document_id', 26);
            $table->unsignedInteger('version');
            $table->string('schema_version', 20);
            $table->dateTime('generated_at');
            $table->dateTime('period_start');
            $table->dateTime('period_end');
            $table->dateTime('stale_after');
            $table->string('utc_offset', 6);
            $table->string('language', 35);
            $table->string('subject', 200);
            $table->text('summary');
            $table->json('sources');
            $table->json('action_ids');
            $table->json('model_provenance');
            $table->string('content_hash', 71);
            $table->string('delivery_state', 20);
            $table->char('idempotency_key', 26);
            BusinessObjectColumns::lineage($table);
            BusinessObjectColumns::timestamps($table);

            $table->unique(['organization_id', 'document_id', 'version'], 'briefs_organization_document_version_unique');
            $table->unique('idempotency_key', 'briefs_idempotency_key_unique');
            $table->index(['organization_id', 'generated_at'], 'briefs_organization_generated_at_index');
            $table->index(['organization_id', 'delivery_state'], 'briefs_organization_delivery_state_index');
            $table->index('stale_after', 'briefs_stale_after_index');
        });
    }
};
