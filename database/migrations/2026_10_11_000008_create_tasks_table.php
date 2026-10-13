<?php

use App\Support\Database\BusinessObjectColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Todos (§3.2). `due_at` is set only when a source or the user stated a
     * deadline (§4.2: no invented deadlines). `priority` shares the brief items'
     * vocabulary so a task can be lifted into a brief unchanged.
     */
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table): void {
            BusinessObjectColumns::ulid($table);
            BusinessObjectColumns::organization($table);
            $table->string('title', 200);
            $table->text('description');
            $table->string('status', 20);
            $table->string('priority', 20);
            $table->dateTime('due_at')->nullable();
            $table->char('customer_id', 26)->nullable();
            $table->char('project_id', 26)->nullable();
            $table->char('contact_id', 26)->nullable();
            BusinessObjectColumns::lineage($table);
            BusinessObjectColumns::timestamps($table);

            $table->foreign('customer_id', 'tasks_customer_fk')->references('id')->on('customers');
            $table->foreign('project_id', 'tasks_project_fk')->references('id')->on('projects');
            $table->foreign('contact_id', 'tasks_contact_fk')->references('id')->on('contacts');
            $table->index(['organization_id', 'status', 'due_at'], 'tasks_organization_status_due_at_index');
            $table->index(['organization_id', 'source_id'], 'tasks_organization_source_index');
            $table->index(['customer_id', 'status'], 'tasks_customer_status_index');
            $table->index(['project_id', 'status'], 'tasks_project_status_index');
            $table->index('contact_id', 'tasks_contact_index');
        });
    }
};
