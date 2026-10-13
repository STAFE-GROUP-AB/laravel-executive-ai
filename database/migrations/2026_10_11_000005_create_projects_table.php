<?php

use App\Support\Database\BusinessObjectColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Projects per customer with status and deadlines (§3.2). `starts_on` and
     * `due_on` are calendar dates, not instants: the fixture's "Consegna ottobre"
     * is due on 17 October wherever the owner happens to be.
     */
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table): void {
            BusinessObjectColumns::ulid($table);
            BusinessObjectColumns::organization($table);
            $table->char('customer_id', 26);
            $table->string('name', 200);
            $table->string('status', 20);
            $table->date('starts_on')->nullable();
            $table->date('due_on')->nullable();
            $table->text('description');
            BusinessObjectColumns::lineage($table);
            BusinessObjectColumns::timestamps($table);

            $table->foreign('customer_id', 'projects_customer_fk')->references('id')->on('customers');
            $table->index(['organization_id', 'status'], 'projects_organization_status_index');
            $table->index(['customer_id', 'status'], 'projects_customer_status_index');
            $table->index(['organization_id', 'due_on'], 'projects_organization_due_on_index');
        });
    }
};
