<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Install-state marker (§12.1, §16 step 4): not_installed, installing,
     * core_ready, ai_ready. Every transition is a row so diagnostics can show
     * the history; the current state is the newest row. It is not a business
     * object (no organization, no provenance) and lives outside the cache so
     * `cache:clear` cannot forget that the install completed.
     */
    public function up(): void
    {
        Schema::create('install_state', function (Blueprint $table): void {
            $table->char('id', 26);
            $table->string('state', 20);
            $table->string('app_version', 40);
            $table->string('reason', 200)->nullable();
            $table->dateTime('changed_at');

            $table->primary('id', 'install_state_pk');
            $table->index('changed_at', 'install_state_changed_at_index');
        });
    }
};
