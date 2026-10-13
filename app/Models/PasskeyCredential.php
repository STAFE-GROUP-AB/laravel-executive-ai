<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A registered passkey (S2-05, docs/architecture/auth.md §7). The stored
 * public key is not a secret, but the credential id and the key are still
 * hidden from serialisation so a dump of the model never carries them into a
 * log line or a diagnostics export by accident.
 *
 * The unique constraint is on `credential_id_hash`, not on `credential_id`
 * (#117): a credential id is up to 1364 base64url characters, which no InnoDB
 * key can hold, so the identity of a credential is indexed through a
 * fixed-width SHA-256 of it. `hashFor()` is the single definition, the saving
 * hook keeps the column in step with `credential_id` on every write, and
 * `forCredentialId()` is how a caller looks one up — nothing should query
 * `credential_id` directly, because that column is deliberately unindexed.
 *
 * @property string $id
 * @property string $user_id
 * @property string $name
 * @property string $credential_id base64url, unpadded; up to 1364 characters
 * @property string $credential_id_hash sha256 hex of `credential_id`; carries the unique index
 * @property string $public_key base64 of the COSE key bytes
 * @property string $user_handle
 * @property int $sign_count
 * @property string $aaguid
 * @property list<string> $transports
 * @property string $attestation_type
 * @property array<string, mixed> $trust_path
 * @property ?bool $backup_eligible
 * @property ?bool $backed_up
 * @property ?bool $uv_initialized
 * @property string $rp_id
 * @property string $origin
 * @property ?CarbonImmutable $last_used_at
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 */
#[Hidden(['credential_id', 'credential_id_hash', 'public_key', 'user_handle', 'trust_path'])]
class PasskeyCredential extends Model
{
    use HasUlids;

    /**
     * Longest credential id the WebAuthn Level 2 spec permits, in characters of
     * its stored form: a credential id is at most 1023 bytes, and unpadded
     * base64url of 1023 bytes is `ceil(1023 / 3) * 4 = 1364` characters.
     */
    public const int MAX_CREDENTIAL_ID_LENGTH = 1364;

    protected $guarded = [];

    /**
     * The one definition of what `credential_id_hash` holds. Plain SHA-256 of the
     * stored base64url text — not a keyed or salted digest, because this is an
     * index key that two independent processes (a registration and a later
     * assertion) must derive identically, not a password hash. The credential id
     * is not a secret either: the authenticator hands it to any relying party
     * that asks.
     */
    public static function hashFor(string $credentialId): string
    {
        return hash('sha256', $credentialId);
    }

    /**
     * Look a credential up by its id, through the index that actually exists.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeForCredentialId(Builder $query, string $credentialId): Builder
    {
        return $query->where('credential_id_hash', self::hashFor($credentialId));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sign_count' => 'integer',
            'transports' => 'array',
            'trust_path' => 'array',
            'backup_eligible' => 'boolean',
            'backed_up' => 'boolean',
            'uv_initialized' => 'boolean',
            'last_used_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The hash is derived, never supplied: every save recomputes it from
     * `credential_id`, so no call site can insert a row whose index key does not
     * match its credential id, and none has to remember to set it. A save that
     * leaves `credential_id` untouched leaves the hash alone too.
     */
    protected static function booted(): void
    {
        static::saving(function (self $credential): void {
            if (! $credential->isDirty('credential_id') && $credential->exists) {
                return;
            }

            $credential->credential_id_hash = self::hashFor($credential->credential_id);
        });
    }
}
