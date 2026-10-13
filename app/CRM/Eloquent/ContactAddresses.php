<?php

declare(strict_types=1);

namespace App\CRM\Eloquent;

use App\Models\CRM\ContactEmail;

/**
 * The one place this module looks a contact up **by email address**.
 *
 * `contact_emails.address` is encrypted at rest (S2-04, #87): the column holds
 * ciphertext from a randomised encrypter, so the same address yields different
 * bytes every time and the column cannot be compared, prefixed or `LIKE`d in
 * SQL at all. The identity of an address is indexed through `address_hash`, a
 * keyed HMAC, and `ContactEmail::forAddress()` is the scope that hashes and
 * hits that index — including under each previous application key, so a lookup
 * still finds a row an interrupted key rotation has not rewritten yet.
 *
 * Both reads here go through that scope, and they are the module's only ones.
 * Keeping them in one class is what made the encryption a two-line change
 * rather than a hunt, and `tests/Feature/CRM/ContactsCrudTest.php` ("finds a
 * contact by any of its addresses", "refuses an address another contact
 * already uses") is what proves the lookup still works through the index it
 * actually has.
 *
 * Nothing else in this module touches the column. In particular the full-text
 * search does **not**: the `contacts` corpus covers `display_name`,
 * `given_name`, `family_name`, `title` and `notes` only, and
 * `CrmSearchContractTest` pins that addresses are not indexed on either profile
 * ("contacts · addresses are not indexed"). Sorting is by `display_name`. The
 * encrypted address therefore changes the CRM search not at all.
 *
 * Addresses are stored lower-cased and are unique per organization, which is
 * what makes "the sender address is contact X's" a deterministic rule (§5.6).
 * Normalisation is `ContactEmail::normalize()` — the same function the hash is
 * taken over, so a lookup can never normalise differently from the index.
 */
final class ContactAddresses
{
    public static function normalize(string $address): string
    {
        return ContactEmail::normalize($address);
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
            ->forAddress($normalized)
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
            ->forAddress($normalized)
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
