<?php

declare(strict_types=1);

namespace App\Security\Secrets;

use SensitiveParameter;

/**
 * The index key for an encrypted column (S2-04, #87).
 *
 * Laravel's encrypter is randomised: `Encrypter::encrypt()` draws a fresh IV, so the
 * same plaintext yields different ciphertext every time. A unique index over an
 * encrypted column therefore constrains nothing and an equality lookup never hits —
 * which is why a column that was unique, or that a query matches exactly, needs a
 * fixed-width derived value to carry the index. `passkey_credentials.credential_id_hash`
 * (#117) established the shape; this class is the keyed version of it.
 *
 * **Why keyed, where the passkey hash is not.** A credential id is not a secret — the
 * authenticator hands it to any relying party that asks — so a plain SHA-256 of it
 * leaks nothing and two independent processes can derive it with no shared state. An
 * email address is the opposite: the set of plausible addresses is small enough to
 * enumerate, so an unkeyed digest of one is reversible by dictionary and would hand
 * back exactly the value the ciphertext next to it was there to protect. The index key
 * is therefore an HMAC under a key derived from `core.app-key`, which lives in the
 * Keychain and not in the database (ADR-003 D1): someone holding the SQLite file alone
 * can neither read the column nor test a guess against the index.
 *
 * **The domain string matters.** The HMAC key is derived per column
 * (`hash_hmac(sha256, domain, appKey)`), so the same address under two columns produces
 * two unrelated index keys and an attacker who learns one cannot join the other. The
 * domain carries a version suffix so a future change of derivation is a new domain
 * rather than a silent reinterpretation of the stored column.
 *
 * **Rotation.** Because the key is derived from the application key, rotating that key
 * changes every index key — so `AppKeyRotation` rewrites the hash columns in the same
 * pass as the ciphertext (ADR-003 D8). While a rotation is in flight a row may still
 * carry its hash under a `previous[]` key, so a lookup asks for every candidate
 * (`candidates()`) rather than only the current one, exactly as the encrypter tries
 * previous keys when the current one fails to decrypt (E35).
 */
final class BlindIndex
{
    /** Domain separation for `contact_emails.address_hash`. */
    public const string CONTACT_EMAIL_ADDRESS = 'contact_emails.address.v1';

    /** The index key for `$value` under the current application key. */
    public static function hash(string $domain, #[SensitiveParameter] string $value): string
    {
        return self::digest($domain, $value, self::applicationKeys()[0] ?? '');
    }

    /**
     * Every index key `$value` could be stored under: the current application key
     * first, then each `previous[]` key, so a lookup still finds a row that an
     * interrupted rotation has not reached yet.
     *
     * @return non-empty-list<string>
     */
    public static function candidates(string $domain, #[SensitiveParameter] string $value): array
    {
        $candidates = [];

        foreach (self::applicationKeys() as $key) {
            $candidates[] = self::digest($domain, $value, $key);
        }

        return $candidates === [] ? [self::digest($domain, $value, '')] : $candidates;
    }

    /** Width of the stored column: SHA-256 as lower-case hex. */
    public static function width(): int
    {
        return 64;
    }

    private static function digest(string $domain, #[SensitiveParameter] string $value, #[SensitiveParameter] string $key): string
    {
        return hash_hmac('sha256', $value, hash_hmac('sha256', $domain, $key, true));
    }

    /**
     * `app.key` then `app.previous_keys`, decoded to raw bytes.
     *
     * Read from the config repository rather than from the encrypter, because the
     * `Illuminate\Contracts\Encryption\Encrypter` contract exposes no key accessor and
     * `app.key` is what `SecretsServiceProvider` sets from the `laravel_app_key`
     * envelope in the first place (ADR-003 D3.3). The decode mirrors the one
     * `AppServiceProvider` already does for `OtpHasher`.
     *
     * @return list<string>
     */
    private static function applicationKeys(): array
    {
        /** @var array<array-key, mixed> $previous */
        $previous = (array) config('app.previous_keys', []);

        $keys = [];

        foreach ([config('app.key'), ...array_values($previous)] as $key) {
            if (! is_string($key) || $key === '') {
                continue;
            }

            $keys[] = self::decode($key);
        }

        return $keys;
    }

    private static function decode(#[SensitiveParameter] string $key): string
    {
        if (! str_starts_with($key, 'base64:')) {
            return $key;
        }

        $decoded = base64_decode(substr($key, 7), true);

        return $decoded === false ? $key : $decoded;
    }
}
