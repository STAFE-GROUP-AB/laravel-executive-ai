<?php

use App\Support\Database\BusinessObjectColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Promises extracted from a communication (§3.1 `commitments`, §3.2 "komma
     * ihåg lovade uppföljningar"). `follow_up_task_id` is the task created to
     * honour the promise. `brief_item_id` is the brief DOCUMENT's item id
     * (`brief_items.item_id`, stable across versions), not a row key, so it has
     * no foreign key: every version row of a brief repeats the same item id.
     */
    public function up(): void
    {
        Schema::create('commitments', function (Blueprint $table): void {
            BusinessObjectColumns::ulid($table);
            BusinessObjectColumns::organization($table);
            $table->char('communication_id', 26);
            $table->char('customer_id', 26);
            $table->char('contact_id', 26)->nullable();
            $table->string('direction', 20);
            $table->text('text');
            $table->dateTime('promised_at');
            $table->dateTime('due_at')->nullable();
            $table->string('status', 20);
            $table->char('follow_up_task_id', 26)->nullable();
            $table->char('brief_item_id', 26)->nullable();
            $table->string('extracted_by', 10);
            BusinessObjectColumns::lineage($table);
            BusinessObjectColumns::timestamps($table);

            $table->foreign('communication_id', 'commitments_communication_fk')->references('id')->on('communications');
            $table->foreign('customer_id', 'commitments_customer_fk')->references('id')->on('customers');
            $table->foreign('contact_id', 'commitments_contact_fk')->references('id')->on('contacts');
            $table->foreign('follow_up_task_id', 'commitments_follow_up_task_fk')->references('id')->on('tasks');
            $table->index(['organization_id', 'status', 'due_at'], 'commitments_organization_status_due_at_index');
            $table->index(['customer_id', 'status'], 'commitments_customer_status_index');
            $table->index('communication_id', 'commitments_communication_index');
            $table->index('follow_up_task_id', 'commitments_follow_up_task_index');
            $table->index(['organization_id', 'brief_item_id'], 'commitments_organization_brief_item_index');
        });
    }
};
