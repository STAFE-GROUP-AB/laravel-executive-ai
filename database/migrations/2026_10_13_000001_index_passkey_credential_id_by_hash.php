<?php

use App\Models\PasskeyCredential;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fixes #117: `passkey_credentials` could not be migrated on the ADR-002 D1
     * `server` profile at all.
     *
     * `2026_10_11_000001_create_passkey_credentials_table.php` put a unique index
     * directly on `credential_id`, declared `string(..., 1024)`. On MySQL that is
     * 1024 × 4 bytes of `utf8mb4` = **4096 bytes against InnoDB's 3072-byte key
     * limit**, so `migrate` raised `SQLSTATE[42000] 1071` on that file — and because
     * migrations run in order, every table after it was never created. Six green CI
     * checks missed it because no lane runs MySQL; this change ships that lane too.
     *
     * Shortening the column was not an option: a WebAuthn credential id is up to
     * 1023 bytes (§6.3.2 of the Level 2 spec), whose unpadded base64url form reaches
     * **1364 characters**, so `1024` was already short for a conforming
     * authenticator. The portable shape is the one every long-identifier index uses:
     * keep the value in a wide column with **no** index, and put the unique index on
     * a fixed-width hash of it. `credential_id_hash` is `char(64)` — SHA-256 as hex,
     * 256 bytes of `utf8mb4` key, comfortably inside the limit on both engines.
     *
     * **This migration is one half of the fix; the other half amends the `create`
     * migration itself.** A follow-up migration alone cannot repair a *clean* MySQL
     * database, because `create_passkey_credentials` runs first and used to fail
     * before anything else could — so that file was amended too, which is safe
     * precisely because `migrate` never re-runs an applied migration
     * (`Migrator::pendingMigrations()`). This file is what brings forward a database
     * that already ran the original shape: local installs have one, and this project
     * never rebuilds a database (`CLAUDE.md`, ALDRIG TÖMMA EN DATABAS; ADR-002 D5
     * rolls a shipped install back by restoring a verified snapshot, never by a
     * `down()`). The column is added, backfilled from the rows already there, and
     * only then does the index move. Every step is guarded, so on a database created
     * from the amended `create` this migration is a no-op.
     *
     * **Why `credential_id` is widened only off SQLite.** Measured, not assumed.
     * Two facts, both asserted by
     * `tests/Feature/Auth/PasskeyCredentialRollForwardTest.php`:
     *
     *  - SQLite does not enforce a `VARCHAR` length, and Laravel's SQLite grammar
     *    does not even emit one — the stored DDL in `sqlite_master` reads
     *    `"credential_id" varchar not null`, so the original migration's `1024`
     *    never reached the `local` profile and a 1364-character id already
     *    round-trips there unchanged.
     *  - MySQL here runs `STRICT_TRANS_TABLES`, so the same value against
     *    `varchar(1024)` is a hard error, not a silent truncation.
     *
     * So widening is necessary on MySQL and would change nothing observable on
     * SQLite — where it is also the one step that would cost something, because
     * Laravel's SQLite grammar implements `->change()` by **rebuilding the table**
     * (`SQLiteGrammar::compileChange()` is empty; the work happens in the table
     * alteration path), copying the owner's registered passkeys through a temporary
     * table with foreign keys disabled. Adding a column, dropping an index and
     * creating an index are plain statements there — `compileUnique()` and
     * `compileDropUnique()` compile to `create unique index` and `drop index`, not to
     * a table constraint — so on the profile the owner actually runs, this migration
     * rewrites no existing row's storage at all.
     */
    public function up(): void
    {
        // Every step is conditional, because this migration has to be correct on two
        // different starting points: a database that ran the original
        // `create_passkey_credentials` (it does the whole repair) and one created
        // from the amended file, which already has the right shape (it does nothing).
        // A fresh install runs both files in order, so the no-op path is the common
        // one and has to be exactly that.
        if (! Schema::hasColumn('passkey_credentials', 'credential_id_hash')) {
            Schema::table('passkey_credentials', function (Blueprint $table): void {
                // Not nullable: a nullable column under a unique index constrains
                // nothing, because both engines treat NULL as distinct. The default
                // exists only so `ADD COLUMN` is legal against rows already there;
                // the backfill below replaces it, and `PasskeyCredential` keeps the
                // column in step with `credential_id` on every save from here on.
                $table->char('credential_id_hash', 64)->default('')->after('credential_id');
            });
        }

        $this->backfillHashes();

        // The old index has to go before the column can grow: on MySQL, widening a
        // column that still carries a 4096-byte unique index is refused for the same
        // reason the original migration was.
        if ($this->hasIndex('passkey_credentials_credential_id_unique')) {
            Schema::table('passkey_credentials', function (Blueprint $table): void {
                $table->dropUnique('passkey_credentials_credential_id_unique');
            });

            if (Schema::getConnection()->getDriverName() !== 'sqlite') {
                Schema::table('passkey_credentials', function (Blueprint $table): void {
                    $table->string('credential_id', PasskeyCredential::MAX_CREDENTIAL_ID_LENGTH)->change();
                });
            }
        }

        if (! $this->hasIndex('passkey_credentials_credential_id_hash_unique')) {
            Schema::table('passkey_credentials', function (Blueprint $table): void {
                $table->unique('credential_id_hash', 'passkey_credentials_credential_id_hash_unique');
            });
        }
    }

    private function hasIndex(string $name): bool
    {
        foreach (Schema::getIndexes('passkey_credentials') as $index) {
            if ($index['name'] === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * SHA-256 has to be computed in PHP: SQLite has no `sha2()` function, so a
     * single `UPDATE ... SET hash = SHA2(...)` is not portable. Rows are read in
     * chunks and written one by one, which is the shape that stays correct whether
     * the table holds the two passkeys of one owner's Mac or a developer's
     * accumulated test rows.
     *
     * Only rows still carrying the `ADD COLUMN` default are touched, so this is a
     * single empty `SELECT` on a database that never had the old shape.
     *
     * **`chunkById`, not `chunk`, and the difference is a correctness bug.** The
     * filter is `credential_id_hash = ''` and the body writes a non-empty hash into
     * that same column, so every row this loop touches *leaves the result set*.
     * `chunk()` pages by raw offset — `$offset = (($page - 1) * $count) + $skip`
     * then `offset($offset)->limit($limit)` — so page 2 would read offset 500 of a
     * set that had just shrunk by 500 and skip exactly the rows that moved into
     * that range. `chunkById()` pages with `forPageAfterId($limit, $lastId, $column)`,
     * a keyset predicate on the primary key, which is immune to a filter the loop
     * mutates. ULIDs sort lexicographically, so `id > $lastId` is a correct cursor.
     *
     * The first version of this method used `chunk()`. At exactly
     * `BACKFILL_CHUNK + 1` rows it left one row unhashed **silently**: `chunk()`
     * stops when a page returns fewer rows than the chunk size, one leftover row is
     * not a duplicate, and the unique index below is created happily — so a passkey
     * existed that `forCredentialId()` could never find. That is the case
     * `PasskeyCredentialRollForwardTest` now pins.
     */
    private function backfillHashes(): void
    {
        DB::table('passkey_credentials')
            ->select('id', 'credential_id')
            ->where('credential_id_hash', '')
            ->chunkById(PasskeyCredential::BACKFILL_CHUNK, function (Collection $rows): void {
                foreach ($rows as $row) {
                    DB::table('passkey_credentials')
                        ->where('id', $row->id)
                        ->update(['credential_id_hash' => PasskeyCredential::hashFor((string) $row->credential_id)]);
                }
            }, 'id');
    }
};
