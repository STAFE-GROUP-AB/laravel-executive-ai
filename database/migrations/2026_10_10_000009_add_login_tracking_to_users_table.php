<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Login bookkeeping for the owner account (S1-02): when the last
     * one-time code was verified and how many times. Shown in settings and
     * useful to a diagnostic export without revealing anything secret.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dateTime('last_login_at')->nullable();
            $table->unsignedInteger('login_count')->default(0);
        });
    }
};
