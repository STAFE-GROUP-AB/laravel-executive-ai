<?php

use App\Support\Database\BusinessObjectColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §3.1 `task_assignees`: users responsible for a task. Users are never part
     * of the fixture pack; the owner's ULID is read from `customers[0].owner_user_id`.
     */
    public function up(): void
    {
        Schema::create('task_assignees', function (Blueprint $table): void {
            BusinessObjectColumns::ulid($table);
            BusinessObjectColumns::organization($table);
            $table->char('task_id', 26);
            $table->char('user_id', 26);
            BusinessObjectColumns::lineage($table);
            BusinessObjectColumns::timestamps($table);

            $table->foreign('task_id', 'task_assignees_task_fk')->references('id')->on('tasks');
            $table->foreign('user_id', 'task_assignees_user_fk')->references('id')->on('users');
            $table->unique(['task_id', 'user_id'], 'task_assignees_task_user_unique');
            $table->index(['user_id', 'organization_id'], 'task_assignees_user_organization_index');
        });
    }
};
