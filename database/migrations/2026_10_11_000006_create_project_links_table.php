<?php

use App\Support\Database\BusinessObjectColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §3.1 `project_links`: the GitHub repositories, issues and pull requests a
     * project is tied to (§3.2 "GitHub-länkar"). The matcher uses them to link a
     * GitHub item to the project's customer (fixture: PR #212 → São Paulo rollout).
     */
    public function up(): void
    {
        Schema::create('project_links', function (Blueprint $table): void {
            BusinessObjectColumns::ulid($table);
            BusinessObjectColumns::organization($table);
            $table->char('project_id', 26);
            $table->string('kind', 20);
            $table->string('uri', 2000);
            $table->string('label', 200)->nullable();
            $table->unsignedInteger('position');
            BusinessObjectColumns::lineage($table);
            BusinessObjectColumns::timestamps($table);

            $table->foreign('project_id', 'project_links_project_fk')->references('id')->on('projects');
            $table->unique(['project_id', 'position'], 'project_links_project_position_unique');
            $table->index(['project_id', 'kind'], 'project_links_project_kind_index');
        });
    }
};
