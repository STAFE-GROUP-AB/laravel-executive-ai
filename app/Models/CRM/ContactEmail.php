<?php

declare(strict_types=1);

namespace App\Models\CRM;

use App\CRM\Contacts\ContactEmailLabel;
use App\Models\Concerns\EncryptsSensitiveAttributes;
use App\Security\Secrets\BlindIndex;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One address a contact is known by, lower-cased, unique per organization.
 *
 * `address` is **encrypted at rest** (S2-04, blueprint §3.1, ADR-003 D1): it is the
 * strongest identifier a natural person has in this schema, and the row it sits on is
 * `sensitivity_class = personal`. Encryption costs the column its index, because
 * Laravel's encrypter is randomised and the same address yields different ciphertext
 * every time — so the identity of an address is indexed through `address_hash`, a
 * keyed HMAC of it, exactly as the identity of a passkey is indexed through
 * `passkey_credentials.credential_id_hash` (#117). The unique index keeps its name,
 * `contact_emails_organization_address_unique`, and moves onto
 * `(organization_id, address_hash)`.
 *
 * `hashFor()` is the single definition, the `saving` hook recomputes the column from
 * `address` on every write so no row can hold an index key that disagrees with its
 * address, and `forAddress()` is how a caller looks one up — §5.6's "the sender
 * address is the primary address of contact X" resolves through that scope and
 * nothing queries `address` directly, because that column can no longer be matched.
 *
 * The hash is taken over the trimmed, lower-cased address. Addresses were already
 * stored lower-cased (§3.2, and the S0-18 pack asserts it), so this changes no
 * existing row; it does mean the unique index now rejects two spellings of one
 * address that differ only in case, which is the rule the column was documented to
 * follow all along.
 *
 * @property string $contact_id
 * @property string $address
 * @property string $address_hash keyed HMAC of the lower-cased `address`; carries the unique index
 * @property ContactEmailLabel $label
 * @property bool $is_primary
 * @property int $position
 */
#[Hidden(['address_hash'])]
class ContactEmail extends CrmModel
{
    use EncryptsSensitiveAttributes;

    /**
     * Rows per chunk when the S2-04 migration backfills `address_hash` and the
     * ciphertext, and when `AppKeyRotation` rewrites them. A constant rather than a
     * literal so the test that proves the backfill crosses a chunk boundary derives
     * its row count from the value the migration actually uses — the same reason
     * `PasskeyCredential::BACKFILL_CHUNK` is one (#117).
     */
    public const int BACKFILL_CHUNK = 500;

    /** The one definition of what `address_hash` holds. */
    public static function hashFor(string $address): string
    {
        return BlindIndex::hash(BlindIndex::CONTACT_EMAIL_ADDRESS, self::normalize($address));
    }

    /**
     * Every hash the address could be stored under — the current application key and
     * each `previous[]` key — so a lookup still finds a row that an interrupted
     * `AppKeyRotation` has not rewritten yet (ADR-003 D8, E35).
     *
     * @return non-empty-list<string>
     */
    public static function hashCandidatesFor(string $address): array
    {
        return BlindIndex::candidates(BlindIndex::CONTACT_EMAIL_ADDRESS, self::normalize($address));
    }

    public static function normalize(string $address): string
    {
        return mb_strtolower(trim($address));
    }

    /**
     * Look an address up through the index that actually exists.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeForAddress(Builder $query, string $address): Builder
    {
        return $query->whereIn('address_hash', self::hashCandidatesFor($address));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            ...parent::casts(),
            ...$this->encryptedAttributeCasts(),
            'label' => ContactEmailLabel::class,
            'is_primary' => 'boolean',
            'position' => 'integer',
        ];
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * The hash is derived, never supplied: every save that touches `address`
     * recomputes it, so no call site can insert a row whose index key does not match
     * its address, and none has to remember to set it.
     *
     * `isDirty('address')` is deliberately not the guard an unencrypted column would
     * use. Once `app.previous_keys` is non-empty Eloquent treats every encrypted
     * attribute as dirty (`originalIsEquivalent()` returns false for them outright),
     * and while it is empty it compares the *decrypted* values — so the flag answers
     * "did the plaintext change", which is exactly the question here, but only
     * because `getAttribute()` decrypts. A row that cannot be decrypted would throw
     * from the comparison rather than quietly write a wrong hash, which is the
     * behaviour we want.
     */
    protected static function booted(): void
    {
        static::saving(function (self $email): void {
            if ($email->exists && ! $email->isDirty('address')) {
                return;
            }

            $email->address_hash = self::hashFor($email->address);
        });
    }
}
