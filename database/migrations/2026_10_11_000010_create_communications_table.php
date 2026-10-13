<?php

use App\Support\Database\BusinessObjectColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-002 D9: the source-agnostic CRM timeline projection — one row per link
     * between a source item and a customer or contact. `source_type` + the §3.1
     * `source_id` (from BusinessObjectColumns::lineage) form the morph to the
     * system of record; there is deliberately NO foreign key to `email_messages`
     * (that table lands with E3-2, D9.2) nor to `email_connections` (S2-03), so
     * `account_id` is a plain ULID column. The match is explainable (§5.6):
     * `match_reason`, `matched_by`, `confidence`, and `correction_of` keeps the
     * match a user replaced so the correction stays auditable.
     */
    public function up(): void
    {
        Schema::create('communications', function (Blueprint $table): void {
            BusinessObjectColumns::ulid($table);
            BusinessObjectColumns::organization($table);
            $table->string('source_type', 40);
            $table->char('account_id', 26)->nullable();
            $table->string('account_label', 200)->nullable();
            $table->string('direction', 20);
            $table->dateTime('occurred_at');
            $table->string('subject', 200);
            $table->text('excerpt');
            $table->string('from_address', 320)->nullable();
            $table->json('to_addresses');
            $table->char('customer_id', 26)->nullable();
            $table->char('contact_id', 26)->nullable();
            $table->string('match_reason', 200);
            $table->string('matched_by', 10);
            $table->decimal('confidence', 4, 3);
            $table->json('correction_of')->nullable();
            BusinessObjectColumns::lineage($table);
            BusinessObjectColumns::timestamps($table);

            $table->foreign('customer_id', 'communications_customer_fk')->references('id')->on('customers');
            $table->foreign('contact_id', 'communications_contact_fk')->references('id')->on('contacts');
            $table->index(['organization_id', 'occurred_at'], 'communications_organization_occurred_at_index');
            $table->index(['organization_id', 'source_type', 'source_id'], 'communications_organization_source_index');
            $table->index(['customer_id', 'occurred_at'], 'communications_customer_occurred_at_index');
            $table->index(['contact_id', 'occurred_at'], 'communications_contact_occurred_at_index');
            $table->index(['account_id', 'occurred_at'], 'communications_account_occurred_at_index');
            $table->index(['organization_id', 'matched_by', 'confidence'], 'communications_organization_match_index');
        });
    }
};
