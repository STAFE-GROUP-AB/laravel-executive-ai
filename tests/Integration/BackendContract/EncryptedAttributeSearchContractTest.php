<?php

declare(strict_types=1);

use App\CRM\Search\FullTextSearch;
use App\CRM\Search\SearchableEntity;
use App\Models\CRM\Contact;
use App\Models\CRM\ContactEmail;
use App\Security\Secrets\EncryptedAttributeInventory;
use App\Security\Secrets\SensitiveAttributeRewriter;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\BackendProfile;
use Tests\Support\CrmFixturePack;

/*
 * S2-04 (#87) on both ADR-002 D1 profiles: encryption at rest did not cost the §3.1
 * search corpus anything, and what it *did* cost — exact matching on an encrypted
 * column — was bought back with a keyed hash.
 *
 * `CrmSearchContractTest` is the frozen behaviour: the same hit set for the same
 * fixture queries on `local` and `server`. It passes unchanged after this item, and
 * this file is the reason that is a real result rather than a coincidence — it asserts
 * that the very corpus those queries run against is carrying ciphertext in its
 * inventoried columns while the indexed columns are still plaintext. Without that
 * pairing, "search still works" could mean nothing more than "nothing was encrypted".
 *
 * The corpus is imported **committed**, for the same reason the frozen file does it:
 * InnoDB publishes rows to a FULLTEXT index when their transaction commits, so a
 * corpus written inside a test transaction is invisible to `MATCH … AGAINST` on the
 * `server` profile. It is therefore also short-lived — imported before the assertions
 * and removed in a `finally`, by id, in the testing environment only. Nothing is
 * dropped, truncated or refreshed.
 */

/** The S0-18 contact whose address the §5.6 sender rule resolves in the fixture pack. */
const SEARCH_CONTACT_ID = '01KH3CETM0PGFQ5HFHQ2A5DNM9';

const SEARCH_CONTACT_ADDRESS = 'karin.nordlund@nordlund.example.se';

function withEncryptedSearchCorpus(Closure $assertions): void
{
    CrmFixturePack::importSearchCorpus();

    try {
        $assertions();
    } finally {
        CrmFixturePack::forgetSearchCorpus();
    }
}

$skip = fn (): bool => BackendProfile::serverWithoutMysql();

$reason = 'server profile selected but no MySQL connection configured';

it('carries ciphertext in the corpus the frozen search queries run against', function (): void {
    withEncryptedSearchCorpus(function (): void {
        $organizationId = CrmFixturePack::mirrorId(CrmFixturePack::organizationId());

        // The corpus is imported through `CrmPackMapper`, i.e. through the models, so
        // every inventoried column in it went through its cast on the way in.
        foreach (EncryptedAttributeInventory::columnsByTable() as $table => $columns) {
            if (! in_array($table, ['contacts', 'contact_emails', 'communications', 'commitments'], true)) {
                continue;
            }

            foreach ($columns as $column) {
                $values = DB::table($table)
                    ->where('organization_id', $organizationId)
                    ->whereNotNull($column)
                    ->pluck($column)
                    ->map(fn (mixed $value): string => (string) $value)
                    ->filter(fn (string $value): bool => $value !== '');

                expect($values)->not->toBeEmpty("the corpus writes {$table}.{$column}");

                foreach ($values as $value) {
                    expect(SensitiveAttributeRewriter::isCiphertext($value))
                        ->toBeTrue("{$table}.{$column} is ciphertext in the search corpus");
                }
            }
        }
    });
})->group('backend-contract')->group('search')->skip($skip, $reason);

it('still returns the frozen hit set over the three indexed entities', function (): void {
    withEncryptedSearchCorpus(function (): void {
        $organizationId = CrmFixturePack::mirrorId(CrmFixturePack::organizationId());
        $search = app(FullTextSearch::class);

        // One query per indexed entity, over a plaintext column of each — the three
        // SearchableEntity cases, so a future item that encrypted an indexed column
        // would fail here as well as in the frozen file.
        expect($search->search(SearchableEntity::Customers, $organizationId, 'nordlund'))
            ->toBe([CrmFixturePack::mirrorId('01M4FJ7ZJRDVPBT5PEZTZWC6BX')])
            ->and($search->search(SearchableEntity::Contacts, $organizationId, 'sjostrom'))
            ->toBe([CrmFixturePack::mirrorId('01KFDACWK0EPRYZ6GG2ZV1HQ2V')])
            ->and($search->search(SearchableEntity::Communications, $organizationId, 'akersberga'))
            ->toBe([CrmFixturePack::mirrorId('01M4DMDZP0GRSEE84KCBFHRDSR')]);

        // And the encrypted columns are not findable through the index, which is the
        // other half of the same statement: an address is not in the corpus's
        // searchable text, before or after this item (the frozen file pins
        // 'contacts · addresses are not indexed' for the same reason).
        expect($search->search(SearchableEntity::Contacts, $organizationId, 'nordlund.example.se'))->toBe([]);
    });
})->group('backend-contract')->group('search')->skip($skip, $reason);

it('resolves an address through the blind index on both profiles', function (): void {
    withEncryptedSearchCorpus(function (): void {
        $contactId = CrmFixturePack::mirrorId(SEARCH_CONTACT_ID);

        // §5.6's "the sender address is the primary address of contact X" — the one
        // exact lookup an encrypted address had to keep. It goes through the keyed
        // hash now, and nothing else can serve it.
        $email = ContactEmail::query()->forAddress(SEARCH_CONTACT_ADDRESS)->firstOrFail();

        expect($email->contact_id)->toBe($contactId)
            ->and($email->address)->toBe(SEARCH_CONTACT_ADDRESS)
            ->and(Contact::query()->findOrFail($contactId)->display_name)->toBe('Karin Nordlund');

        // The queries that would have worked before, and must now find nothing: the
        // column holds randomised ciphertext, so neither equality nor a prefix nor a
        // substring can match it. These are the assertions that catch a call site
        // quietly reverting to `where('address', …)`.
        expect(ContactEmail::query()->where('address', SEARCH_CONTACT_ADDRESS)->exists())->toBeFalse()
            ->and(ContactEmail::query()->where('address', 'like', 'karin.nordlund%')->exists())->toBeFalse()
            ->and(ContactEmail::query()->where('address', 'like', '%nordlund.example.se')->exists())->toBeFalse();
    });
})->group('backend-contract')->group('search')->skip($skip, $reason);

it('keeps one address per workspace unrecordable twice, through the hash', function (): void {
    withEncryptedSearchCorpus(function (): void {
        $existing = ContactEmail::query()->forAddress(SEARCH_CONTACT_ADDRESS)->firstOrFail();

        // The ciphertext of the same address differs every time — Laravel's encrypter
        // draws a fresh IV — so a unique index over the column itself would admit
        // this row. The index is over the hash, which is deterministic under one key,
        // so the insert is refused. That is the whole argument for the blind index,
        // stated as a test on both engines.
        $first = app('encrypter')->encrypt(SEARCH_CONTACT_ADDRESS, false);
        $second = app('encrypter')->encrypt(SEARCH_CONTACT_ADDRESS, false);

        expect($first)->not->toBe($second, 'the encrypter is randomised')
            ->and(ContactEmail::hashFor(SEARCH_CONTACT_ADDRESS))->toBe(ContactEmail::hashFor(SEARCH_CONTACT_ADDRESS));

        $duplicate = $existing->replicate(['id']);
        $duplicate->address = SEARCH_CONTACT_ADDRESS;
        $duplicate->position = 90;

        expect(fn () => $duplicate->save())->toThrow(QueryException::class);
    });
})->group('backend-contract')->group('search')->skip($skip, $reason);
