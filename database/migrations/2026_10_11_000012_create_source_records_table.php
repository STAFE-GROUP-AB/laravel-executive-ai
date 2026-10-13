<?php

use App\Support\Database\BusinessObjectColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §3.1 `source_records`: the provider-agnostic snapshot of one external item
     * as read from a connected account (a GitHub issue, a calendar event, ...) —
     * what `email_messages` (E3-2) is for mail. The unique index is the §3.1
     * "provider/account/external_id" key that makes a replayed sync batch a no-op
     * (ADR-002 D3.3). `external_account_id` gets its foreign key when S2-03
     * lands the accounts table. `payload` is the normalized item, never raw
     * credentials or headers that carry them.
     */
    public function up(): void
    {
        Schema::create('source_records', function (Blueprint $table): void {
            BusinessObjectColumns::ulid($table);
            BusinessObjectColumns::organization($table);
            $table->string('source_type', 40);
            $table->char('external_account_id', 26);
            $table->string('external_id', 200);
            $table->dateTime('fetched_at');
            $table->dateTime('occurred_at')->nullable();
            $table->string('content_hash', 71);
            $table->json('payload')->nullable();
            BusinessObjectColumns::lineage($table);
            BusinessObjectColumns::timestamps($table);

            $table->unique(['organization_id', 'source_type', 'external_account_id', 'external_id'], 'source_records_external_unique');
            $table->index(['organization_id', 'fetched_at'], 'source_records_organization_fetched_at_index');
            $table->index(['external_account_id', 'fetched_at'], 'source_records_account_fetched_at_index');
        });
    }
};
