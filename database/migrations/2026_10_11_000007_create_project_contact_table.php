<?php

use App\Support\Database\BusinessObjectColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Contacts involved in a project (the fixture's `projects[].contact_ids`).
     * Mirrors `customer_contact` so a project's people are a relation with
     * provenance rather than a JSON list of ids nothing can enforce.
     */
    public function up(): void
    {
        Schema::create('project_contact', function (Blueprint $table): void {
            BusinessObjectColumns::ulid($table);
            BusinessObjectColumns::organization($table);
            $table->char('project_id', 26);
            $table->char('contact_id', 26);
            $table->string('role', 200)->nullable();
            BusinessObjectColumns::lineage($table);
            BusinessObjectColumns::timestamps($table);

            $table->foreign('project_id', 'project_contact_project_fk')->references('id')->on('projects');
            $table->foreign('contact_id', 'project_contact_contact_fk')->references('id')->on('contacts');
            $table->unique(['project_id', 'contact_id'], 'project_contact_project_contact_unique');
            $table->index('contact_id', 'project_contact_contact_index');
        });
    }
};
