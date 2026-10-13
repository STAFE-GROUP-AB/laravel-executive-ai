<?php

use App\Support\Database\BusinessObjectColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Registered passkeys (S2-05, docs/architecture/auth.md §7). One row per
     * WebAuthn credential: the credential id, the COSE public key, the sign
     * counter and the backup flags the relying party must track — never a
     * challenge, a client-data blob or a signature, which exist only for the
     * duration of one ceremony. Like otp_codes this is a credential record,
     * not a business object: no organization, provenance or soft delete; a
     * removed passkey is deleted and the removal is an audit event.
     */
    public function up(): void
    {
        Schema::create('passkey_credentials', function (Blueprint $table): void {
            BusinessObjectColumns::ulid($table);
            $table->char('user_id', 26);
            $table->string('name', 100);
            $table->string('credential_id', 1024);
            $table->text('public_key');
            $table->string('user_handle', 64);
            $table->unsignedBigInteger('sign_count')->default(0);
            $table->string('aaguid', 36);
            $table->json('transports');
            $table->string('attestation_type', 20);
            $table->json('trust_path');
            $table->boolean('backup_eligible')->nullable();
            $table->boolean('backed_up')->nullable();
            $table->boolean('uv_initialized')->nullable();
            $table->string('rp_id', 253);
            $table->string('origin', 300);
            $table->dateTime('last_used_at')->nullable();
            BusinessObjectColumns::timestamps($table, softDeletes: false);

            $table->foreign('user_id', 'passkey_credentials_user_fk')->references('id')->on('users');
            $table->unique('credential_id', 'passkey_credentials_credential_id_unique');
            $table->index(['user_id', 'rp_id'], 'passkey_credentials_user_rp_index');
        });
    }
};
