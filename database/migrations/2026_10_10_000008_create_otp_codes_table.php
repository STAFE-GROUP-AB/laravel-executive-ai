<?php

use App\Support\Database\BusinessObjectColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One-time login codes (S1-02, docs/architecture/auth.md). Only the HMAC
     * of the code is stored, never the code; `attempts` drives the lockout
     * and `consumed_at` makes a code single-use. Not a business object: it is
     * a credential record with no organization, provenance or soft delete,
     * and expired rows are pruned, not retained.
     */
    public function up(): void
    {
        Schema::create('otp_codes', function (Blueprint $table): void {
            BusinessObjectColumns::ulid($table);
            $table->char('user_id', 26);
            $table->string('code_hash', 64);
            $table->dateTime('expires_at');
            $table->dateTime('consumed_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('ip_address', 45)->nullable();
            $table->dateTime('created_at')->nullable();

            $table->foreign('user_id', 'otp_codes_user_fk')->references('id')->on('users');
            $table->index(['user_id', 'consumed_at', 'expires_at'], 'otp_codes_user_active_index');
            $table->index('expires_at', 'otp_codes_expires_at_index');
        });
    }
};
