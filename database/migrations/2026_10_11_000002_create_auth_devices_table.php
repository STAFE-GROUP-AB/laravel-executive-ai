<?php

use App\Support\Database\BusinessObjectColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The device list (S2-05, docs/architecture/auth.md §5). One row per
     * sign-in: which session it produced, how the person authenticated, and
     * what the browser said about itself. The `sessions` table stays the
     * source of truth for whether the device is still signed in; this table
     * is what the Devices page lists and what a revocation marks. Not a
     * business object: no organization, provenance or soft delete.
     */
    public function up(): void
    {
        Schema::create('auth_devices', function (Blueprint $table): void {
            BusinessObjectColumns::ulid($table);
            $table->char('user_id', 26);
            $table->string('session_id', 255);
            $table->string('method', 20);
            $table->char('passkey_credential_id', 26)->nullable();
            $table->string('label', 120);
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->dateTime('signed_in_at');
            $table->dateTime('revoked_at')->nullable();
            $table->string('revoked_by', 60)->nullable();
            BusinessObjectColumns::timestamps($table, softDeletes: false);

            $table->foreign('user_id', 'auth_devices_user_fk')->references('id')->on('users');
            $table->unique('session_id', 'auth_devices_session_id_unique');
            $table->index(['user_id', 'revoked_at'], 'auth_devices_user_active_index');
        });
    }
};
