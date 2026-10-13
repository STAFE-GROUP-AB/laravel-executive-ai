<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Security\Secrets\EncryptedAttributeInventory;
use App\Security\Secrets\UnreadableEncryptedAttributeException;
use Closure;
use Throwable;

/**
 * Blueprint §3.1 "sensitive attributes encrypted at rest", ADR-003 D1 (S2-04, #87).
 *
 * A model that uses this trait merges `EncryptedAttributeInventory`'s entry for its
 * own class into `casts()`. It does **not** declare the casts itself, and that is the
 * point: the inventory, the casts and ADR-003 D8's re-encryption job are then one
 * list, so the job cannot silently fall behind a cast somebody added. The arch test
 * `tests/Security/EncryptedAttributeInventoryTest.php` enforces the same thing from
 * the other side.
 *
 * Usage, in the model's own `casts()`:
 *
 *     protected function casts(): array
 *     {
 *         return [...parent::casts(), ...$this->encryptedAttributeCasts()];
 *     }
 *
 * ## Why the read path is overridden
 *
 * Laravel decrypts an `encrypted:` attribute in `castAttribute()` (scalars) and
 * `getArrayAttributeByKey()` (the `encrypted:array` family), both of which call
 * `fromEncryptedString()` and therefore raise
 * `Illuminate\Contracts\Encryption\DecryptException` — "The MAC is invalid." — when no
 * key in `app.previous_keys` fits either. That is already fail-closed, which is the
 * behaviour we want; it is the *message* that is useless to the person reading the
 * log, because it does not say which row, which attribute, or that the application
 * key is the thing that went missing. Both paths are wrapped so the failure arrives
 * as `UnreadableEncryptedAttributeException` instead, and only for attributes this
 * trait actually encrypts — every other cast's exceptions pass through untouched.
 *
 * Nothing here softens the failure. There is deliberately no fallback that returns
 * the ciphertext, no `rescue()` to an empty string and no "maybe it is still
 * plaintext" branch: a row this model cannot decrypt is a row whose contents are
 * gone, and the migration that introduced the casts left no plaintext row behind to
 * be tolerant of.
 */
trait EncryptsSensitiveAttributes
{
    /**
     * This model's entry in the §3.1 encrypted-attribute inventory.
     *
     * @return array<string, string>
     */
    protected function encryptedAttributeCasts(): array
    {
        return EncryptedAttributeInventory::castsFor(static::class);
    }

    /**
     * @param  string  $key
     * @param  mixed  $value
     * @return mixed
     */
    protected function castAttribute($key, $value)
    {
        return $this->readingAnEncryptedAttribute($key, fn (): mixed => parent::castAttribute($key, $value));
    }

    /**
     * @param  string  $key
     * @return mixed
     */
    protected function getArrayAttributeByKey($key)
    {
        return $this->readingAnEncryptedAttribute($key, fn (): mixed => parent::getArrayAttributeByKey($key));
    }

    /**
     * Runs `$read` and, for an encrypted attribute only, turns a decryption failure
     * into one that says what was lost.
     *
     * @param  Closure(): mixed  $read
     */
    private function readingAnEncryptedAttribute(string $key, Closure $read): mixed
    {
        if (! $this->isEncryptedCastable($key)) {
            return $read();
        }

        try {
            return $read();
        } catch (Throwable $throwable) {
            if (! UnreadableEncryptedAttributeException::wraps($throwable)) {
                throw $throwable;
            }

            throw UnreadableEncryptedAttributeException::for(static::class, $key, $throwable);
        }
    }
}
