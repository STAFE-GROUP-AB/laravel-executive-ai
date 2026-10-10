<?php

use App\Support\Database\BusinessObjectColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Owner and user accounts. Login is OTP by email (S1-02); `password` is
     * nullable so an account never needs one. Users carry a ULID like every
     * business object (§3.1) and are scoped to organizations via memberships.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            BusinessObjectColumns::ulid($table);
            $table->string('name');
            $table->string('email')->unique('users_email_unique');
            $table->dateTime('email_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->rememberToken();
            BusinessObjectColumns::timestamps($table);
        });

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->dateTime('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->char('user_id', 26)->nullable()->index('sessions_user_id_index');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index('sessions_last_activity_index');
        });
    }
};
