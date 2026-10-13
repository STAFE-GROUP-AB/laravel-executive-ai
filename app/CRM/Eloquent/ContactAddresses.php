<?php

declare(strict_types=1);

namespace App\CRM\Eloquent;

use App\Models\CRM\ContactEmail;

/**
 * The one place this module looks a contact up **by email address**.
 *
 * It exists because of a single coming change and is deliberately the only
 * call site for it. S2-04 (#87) encrypts `contact_emails.address` at rest: the
 * column becomes ciphertext and the unique index moves to a `char(64)`
 * `address_hash`, after which the address can no longer be compared,
 * prefixed or `LIKE`d in SQL. `ContactEmail::forAddress()` is the lookup that
 * hashes and hits that index.
 *
 * So the predicate lives here, once, and the migration to the hashed lookup is
 * a change to `contactIdsFor()` and nothing else. The alarm that the change is
 * due is a test, not a comment: `tests/Feature/CRM/ContactsCrudTest.php`
 * ("finds a contact by any of its addresses", "refuses an address another
 * contact already uses") fails the moment the column stops being matchable.
 *
 * Nothing else in this module touches the column. In particular the full-text
 * search does **not**: the `contacts` corpus covers `display_name`,
 * `given_name`, `family_name`, `title` and `notes` only, and
 * `CrmSearchContractTest` already pins that addresses are not indexed on either
 * profile ("contacts · addresses are not indexed"). Sorting is by
 * `display_name`. An encrypted address therefore changes the search not at all.
 *
 * Addresses are stored lower-cased and are unique per organization, which is
 * what makes "the sender address is contact X's" a deterministic rule (§5.6).
 * Normalisation happens here so a caller cannot skip it.
 */
final class ContactAddresses
{
    public static function normalize(string $address): string
    {
        return mb_strtolower(trim($address));
    }

    /**
     * The live contacts in `$organizationId` known by `$address`, as ids. A
     * soft-deleted address is not an answer here — the read contract says a
     * soft-deleted row is never returned; `isTaken()` is the one that counts
     * them, for the reason stated there.
     *
     * Scoped by organization because the uniqueness is per workspace: the same
     * address may legitimately belong to a different person in another
     * workspace, and a lookup that ignored the tenant would be the IDOR shape
     * T-73 describes.
     *
     * @return list<string>
     */
    public static function contactIdsFor(string $organizationId, string $address): array
    {
        $normalized = self::normalize($address);

        if ($normalized === '') {
            return [];
        }

        return ContactEmail::query()
            ->where('organization_id', $organizationId)
            ->where('address', $normalized)
            ->pluck('contact_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }

    /**
     * Whether `$address` is already taken in `$organizationId` by somebody
     * other than `$exceptContactId` — the check a contact form runs before it
     * hits the unique index, so a person sees a field error instead of a 500.
     *
     * It counts **soft-deleted** rows too, and has to: a unique index does not
     * know about `deleted_at`, so an address held by a deleted contact is still
     * held as far as the database is concerned. Answering otherwise would
     * accept the form and then fail at the insert.
     */
    public static function isTaken(string $organizationId, string $address, ?string $exceptContactId = null): bool
    {
        $normalized = self::normalize($address);

        if ($normalized === '') {
            return false;
        }

        $holders = ContactEmail::withTrashed()
            ->where('organization_id', $organizationId)
            ->where('address', $normalized)
            ->pluck('contact_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();

        if ($exceptContactId === null) {
            return $holders !== [];
        }

        return array_values(array_filter($holders, static fn (string $id): bool => $id !== $exceptContactId)) !== [];
    }
}
