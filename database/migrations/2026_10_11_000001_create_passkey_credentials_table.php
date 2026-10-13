<?php

use App\Models\PasskeyCredential;
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
     *
     * **Amended by #117.** As shipped, this file put a unique index straight on
     * `credential_id` at `string(..., 1024)`: 4096 bytes of `utf8mb4` against
     * InnoDB's 3072-byte key limit, so `migrate` aborted here on the ADR-002 D1
     * `server` profile and every table after it was never created. The identity of
     * a credential is now indexed through `credential_id_hash`, and `credential_id`
     * holds the 1364 characters the WebAuthn spec's maximum actually needs.
     *
     * Editing an applied migration is normally forbidden here, and this is the
     * narrow case where it is both safe and necessary. **Safe:** `migrate` only runs
     * files absent from the `migrations` table (`Migrator::pendingMigrations()`
     * rejects the rest), so a database that already ran this file never sees the
     * change — it is brought forward instead by
     * `2026_10_13_000001_index_passkey_credential_id_by_hash`, which is conditional
     * and a no-op on a database created from this amended file. **Necessary:** a
     * follow-up migration cannot repair a *clean* MySQL database, because this file
     * runs first and used to fail before anything else could.
     */
    public function up(): void
    {
        Schema::create('passkey_credentials', function (Blueprint $table): void {
            BusinessObjectColumns::ulid($table);
            $table->char('user_id', 26);
            $table->string('name', 100);
            $table->string('credential_id', PasskeyCredential::MAX_CREDENTIAL_ID_LENGTH);
            // Carries the unique index in place of `credential_id` (#117). The
            // empty-string default is kept so a fresh database and one brought
            // forward by the 2026_10_13 migration declare the same column.
            $table->char('credential_id_hash', 64)->default('');
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
            $table->unique('credential_id_hash', 'passkey_credentials_credential_id_hash_unique');
            $table->index(['user_id', 'rp_id'], 'passkey_credentials_user_rp_index');
        });
    }
};
