<?php

declare(strict_types=1);

namespace App\Security\Secrets;

use App\Models\CRM\ContactEmail;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Rewrites the `EncryptedAttributeInventory` columns in place, row by row (S2-04).
 *
 * Two callers, one loop. The migration
 * `2026_10_13_000013_encrypt_sensitive_attributes_at_rest` calls `encryptPlaintext()`
 * once, to bring the rows a local install already holds forward into the casts;
 * `AppKeyRotation` calls `reencrypt()` whenever `core.app-key` is rotated (ADR-003
 * D8). Both are the same traversal with a different per-value step, which is why they
 * live together: a rotation that visited a different set of columns from the backfill
 * would be a silent correctness bug the first time the two lists drifted.
 *
 * ## Why this works on raw ciphertext and not through the models
 *
 * Every value is read and written with `DB::table()` and handled as the opaque string
 * Laravel's encrypter produces. Going through Eloquent would be wrong twice over:
 *
 *  - **A re-save is not reliably dirty.** `originalIsEquivalent()` special-cases
 *    encrypted attributes: with `app.previous_keys` empty it compares the *decrypted*
 *    values, so re-setting the same plaintext is not a change and `save()` writes
 *    nothing at all. Rotation would appear to succeed and rewrite no row.
 *  - **A cast round-trip is not byte-preserving.** An `encrypted:array` column would
 *    be decoded to PHP and re-encoded, so a payload whose JSON differed from
 *    `json_encode()`'s output — key order, unicode escaping, MySQL's own
 *    normalisation of a `json` column — would come back a different string. Treating
 *    the ciphertext as a string keeps the plaintext byte-identical.
 *
 * ## Paging
 *
 * `chunkById()`, never `chunk()`. Both loops filter on, or write to, the very column
 * they are paging over, and `chunk()` pages by raw offset — page 2 reads offset 500
 * of a result set that the first page just shrank, skipping exactly the rows that
 * moved into that range. At one row past the chunk size it fails *silently*: no error,
 * no duplicate, just a row left behind. That is the bug #117's backfill shipped with
 * and `PasskeyCredentialRollForwardTest` now pins; `chunkById()` pages with a keyset
 * predicate on the ULID primary key, which no filter the loop mutates can disturb.
 */
final class SensitiveAttributeRewriter
{
    public const int CHUNK = 500;

    /**
     * Encrypts every inventoried value that is still plaintext, and leaves every
     * value that is already a Laravel payload exactly as it is.
     *
     * Idempotent by construction, which is what makes it safe as a migration step on
     * two different starting points: a database with rows written before the casts
     * existed (it rewrites them) and one created after (there is nothing to rewrite,
     * and on a fresh install the tables are empty, so each pass is a single empty
     * `SELECT`).
     *
     * @return array<string, int> `<table>.<column>` => rows rewritten
     */
    public function encryptPlaintext(): array
    {
        return $this->rewrite(function (string $value): ?string {
            if (self::isCiphertext($value)) {
                return null;
            }

            return self::encrypter()->encrypt($value, false);
        });
    }

    /**
     * Re-encrypts every inventoried value under the current application key, reading
     * each one with the full key set so a value written under a `previous[]` key is
     * carried forward rather than lost (ADR-003 D8, E35).
     *
     * A value that no key can read is left untouched and reported, not swallowed and
     * not overwritten: a row this cannot decrypt is a row whose contents are already
     * gone, and writing a re-encrypted empty string over it would destroy the only
     * evidence of that.
     *
     * @return array<string, int> `<table>.<column>` => rows rewritten
     */
    public function reencrypt(): array
    {
        return $this->rewrite(function (string $value): ?string {
            if (! self::isCiphertext($value)) {
                // Plaintext here means the S2-04 backfill never reached this row.
                // Encrypting it is the right repair and matches what that migration
                // would have done.
                return self::encrypter()->encrypt($value, false);
            }

            try {
                $plain = self::encrypter()->decrypt($value, false);
            } catch (DecryptException) {
                return null;
            }

            return self::encrypter()->encrypt(is_string($plain) ? $plain : (string) json_encode($plain), false);
        });
    }

    /**
     * Rows whose ciphertext no available key can read — the inventory's half of
     * "has the application key been lost". Zero on a healthy install.
     *
     * @return array<string, int> `<table>.<column>` => unreadable rows
     */
    public function unreadable(): array
    {
        $counts = [];

        foreach (EncryptedAttributeInventory::columnsByTable() as $table => $columns) {
            foreach ($columns as $column) {
                $counts["{$table}.{$column}"] = 0;
            }

            DB::table($table)
                ->select('id', ...$columns)
                ->chunkById(self::CHUNK, function (Collection $rows) use ($table, $columns, &$counts): void {
                    foreach ($rows as $row) {
                        foreach ($columns as $column) {
                            $value = $row->{$column};

                            if (! is_string($value) || $value === '' || ! self::isCiphertext($value)) {
                                continue;
                            }

                            try {
                                self::encrypter()->decrypt($value, false);
                            } catch (DecryptException) {
                                $counts["{$table}.{$column}"]++;
                            }
                        }
                    }
                }, 'id');
        }

        return $counts;
    }

    /**
     * Is `$value` a Laravel encryption payload? Base64 of a JSON envelope carrying
     * `iv`, `value` and `mac` — the shape `Encrypter::encrypt()` produces and
     * `Encrypter::getJsonPayload()` validates.
     *
     * It is a shape test, not a proof: a plaintext value could in principle be
     * base64 of exactly that JSON. For the inventory's columns — an email address, a
     * phone list, a quoted promise, a redacted prompt — that does not happen, and the
     * cost of being wrong in the other direction (re-encrypting an already encrypted
     * value, producing ciphertext nothing can read) is why the test is here at all.
     */
    public static function isCiphertext(string $value): bool
    {
        $decoded = base64_decode($value, true);

        if ($decoded === false) {
            return false;
        }

        $payload = json_decode($decoded, true);

        return is_array($payload)
            && isset($payload['iv'], $payload['value'], $payload['mac'])
            && is_string($payload['iv'])
            && is_string($payload['value']);
    }

    /**
     * The decrypted form of a value that may or may not have been encrypted yet —
     * what the `address_hash` backfill needs, because it has to hash the plaintext
     * whichever side of the ciphertext pass it runs on.
     */
    public static function plaintextOf(string $value): string
    {
        if (! self::isCiphertext($value)) {
            return $value;
        }

        $plain = self::encrypter()->decrypt($value, false);

        return is_string($plain) ? $plain : (string) json_encode($plain);
    }

    /**
     * `contact_emails.address_hash` for every row, derived from whatever form the
     * address is currently in. Returns the number of rows written.
     *
     * @param  bool  $onlyMissing  true for the migration's backfill (rows still
     *                             carrying the `ADD COLUMN` default), false for a
     *                             rotation, where every hash changes because the
     *                             HMAC key is derived from the application key.
     */
    public function rewriteContactEmailHashes(bool $onlyMissing): int
    {
        $written = 0;

        DB::table('contact_emails')
            ->select('id', 'address', 'address_hash')
            ->when($onlyMissing, fn ($query) => $query->where('address_hash', ''))
            ->chunkById(self::CHUNK, function (Collection $rows) use (&$written): void {
                foreach ($rows as $row) {
                    $hash = ContactEmail::hashFor(self::plaintextOf((string) $row->address));

                    if ($hash === (string) $row->address_hash) {
                        continue;
                    }

                    DB::table('contact_emails')->where('id', $row->id)->update(['address_hash' => $hash]);

                    $written++;
                }
            }, 'id');

        return $written;
    }

    /**
     * Walks the whole inventory and applies `$transform` to every non-null value.
     * A transform that returns null leaves the row alone.
     *
     * @param  callable(string): ?string  $transform
     * @return array<string, int>
     */
    private function rewrite(callable $transform): array
    {
        $counts = [];

        foreach (EncryptedAttributeInventory::columnsByTable() as $table => $columns) {
            foreach ($columns as $column) {
                $counts["{$table}.{$column}"] = 0;
            }

            DB::table($table)
                ->select('id', ...$columns)
                ->chunkById(self::CHUNK, function (Collection $rows) use ($table, $columns, $transform, &$counts): void {
                    foreach ($rows as $row) {
                        $update = [];

                        foreach ($columns as $column) {
                            $value = $row->{$column};

                            if (! is_string($value) || $value === '') {
                                continue;
                            }

                            $rewritten = $transform($value);

                            if ($rewritten === null) {
                                continue;
                            }

                            $update[$column] = $rewritten;
                            $counts["{$table}.{$column}"]++;
                        }

                        if ($update !== []) {
                            DB::table($table)->where('id', $row->id)->update($update);
                        }
                    }
                }, 'id');
        }

        return $counts;
    }

    /**
     * The encrypter the casts themselves use — `Model::currentEncrypter()`, which is
     * `encryptUsing()`'s instance when a caller set one and the `Crypt` facade root
     * otherwise. Reading it from the same place the casts do is what keeps a rewrite
     * and a read in agreement.
     */
    private static function encrypter(): Encrypter
    {
        return Model::currentEncrypter();
    }
}
