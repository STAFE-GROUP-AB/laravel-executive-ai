<?php

declare(strict_types=1);

namespace App\CRM\Eloquent;

use App\CRM\Contacts\ContactEmailLabel;
use App\Models\CRM\Contact;
use App\Models\CRM\ContactEmail;
use App\Support\Data\Provenance;
use App\Support\Data\ProvenanceOrigin;
use App\Support\Data\SensitivityClass;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionResolverInterface;

/**
 * Writes the list of addresses a contact is known by (§3.2 "flera
 * e-postadresser"), as rows in `contact_emails`.
 *
 * Three schema facts make this more than a delete-and-reinsert:
 *
 *  1. **Addresses are unique per organization** and **positions are unique per
 *     contact** — and a unique index does not know about `deleted_at`, so a
 *     soft-deleted row still occupies both. Re-adding an address that was
 *     removed last week would collide with its own tombstone.
 *  2. **The rows are personal data under a retention rule**
 *     (`contact_personal_data`, anonymised after three years), so they are
 *     soft-deleted and never destroyed: `forceDelete` would take the retention
 *     evidence with it, which is why it is refused on every table
 *     (authorization.md §3).
 *  3. **The order is shown to a person**, so `position` is meaningful rather
 *     than incidental, and `is_primary` marks the one address shown first.
 *
 * So the sync is: park every existing row's position out of the way, then
 * restore-and-update the addresses that are still wanted, insert the new ones,
 * and soft-delete the rest. "Park" is the one unobvious step — positions are
 * shifted by `POSITION_PARK` one row at a time, highest first, so no
 * intermediate state ever has two rows at the same position. A bulk `+1000`
 * would be shorter and can transiently violate the index on MySQL.
 */
final class ContactEmailSync
{
    /**
     * The offset removed and about-to-be-renumbered rows are parked at. Higher
     * than any list a person will type, so a parked row cannot collide with a
     * final position.
     */
    private const int POSITION_PARK = 1000;

    public function __construct(private readonly ConnectionResolverInterface $connections) {}

    /**
     * @param  list<array{address: string, label: string, primary: bool}>  $addresses  in the order they should be shown
     */
    public function sync(Contact $contact, array $addresses, string $actorUserId, CarbonImmutable $now): void
    {
        $this->connections->connection()->transaction(function () use ($contact, $addresses, $actorUserId, $now): void {
            // The contact came from a scoped read, and `contact_id` is a
            // foreign key to a contact that belongs to exactly one
            // organization — so the rows below are already this workspace's.
            // The organization predicate is here anyway, because "every CRM
            // query carries it" is cheaper to keep true than to reason about
            // per call site, and `withTrashed()` is a place where a reader
            // should not have to.
            $existing = ContactEmail::withTrashed()
                ->where('organization_id', $contact->organization_id)
                ->where('contact_id', $contact->getKey())
                ->orderByDesc('position')
                ->get();

            foreach ($existing as $row) {
                $row->position = $row->position + self::POSITION_PARK;
                $row->saveQuietly();
            }

            $kept = [];

            foreach ($addresses as $position => $address) {
                $normalized = ContactAddresses::normalize($address['address']);

                if ($normalized === '') {
                    continue;
                }

                $row = $existing->first(static fn (ContactEmail $candidate): bool => ContactAddresses::normalize($candidate->address) === $normalized)
                    ?? $this->newRow($contact, $actorUserId);

                if ($row->trashed()) {
                    $row->deleted_at = null;
                }

                $row->forceFill([
                    'address' => $normalized,
                    'label' => ContactEmailLabel::tryFrom($address['label']) ?? ContactEmailLabel::Work,
                    'is_primary' => $address['primary'],
                    'position' => $position,
                ]);

                RetentionStamp::apply($row, $now);
                $row->save();

                $kept[] = (string) $row->getKey();
            }

            foreach ($existing as $row) {
                if (in_array((string) $row->getKey(), $kept, true) || $row->trashed()) {
                    continue;
                }

                $row->delete();
                RetentionStamp::apply($row, $now);
                $row->saveQuietly();
            }
        });
    }

    private function newRow(Contact $contact, string $actorUserId): ContactEmail
    {
        $row = new ContactEmail;
        $row->forceFill([
            'organization_id' => $contact->organization_id,
            'contact_id' => $contact->getKey(),
            'sensitivity_class' => SensitivityClass::Personal,
            'provenance' => Provenance::user($actorUserId, ProvenanceOrigin::Manual),
        ]);

        return $row;
    }
}
