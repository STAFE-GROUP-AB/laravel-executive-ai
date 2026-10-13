<?php

declare(strict_types=1);

use App\CRM\Search\FullTextSearch;
use App\CRM\Search\SearchableEntity;
use App\Support\Data\Provenance;
use App\Support\Data\ProvenanceOrigin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BackendProfile;
use Tests\Support\CrmFixturePack;

/*
 * ADR-002 D10 `search` group: the FullTextSearch fixture queries return the same
 * hit SET on both D1 profiles (ranking may differ), and the trigger-maintained
 * index stays consistent after insert, update and delete.
 *
 * This is the one file in the suite without DatabaseTransactions, on purpose:
 * InnoDB publishes rows to a FULLTEXT index when their transaction commits, so a
 * corpus written inside the test transaction is invisible to MATCH … AGAINST on
 * the `server` profile. Each test here therefore imports the corpus committed —
 * under mirrored ULIDs, in the testing environment only — and removes it again in
 * a `finally`, so no other test ever sees a workspace or a CRM row it did not
 * create. Nothing is dropped, truncated or refreshed; rows are deleted by id.
 */

function withSearchCorpus(Closure $assertions): void
{
    CrmFixturePack::importSearchCorpus();

    try {
        $assertions();
    } finally {
        CrmFixturePack::forgetSearchCorpus();
    }
}

function searchCorpusOrganizationId(): string
{
    return CrmFixturePack::mirrorId(CrmFixturePack::organizationId());
}

/** @param list<string> $expected pack ULIDs, in the corpus's mirrored form */
function expectHits(SearchableEntity $entity, string $query, array $expected): void
{
    $hits = app(FullTextSearch::class)->search($entity, searchCorpusOrganizationId(), $query);

    expect($hits)->toBe(array_map(CrmFixturePack::mirrorId(...), $expected));
}

it('returns the same hit set for the frozen fixture queries on both profiles', function (string $entity, string $query, array $expected): void {
    withSearchCorpus(fn () => expectHits(SearchableEntity::from($entity), $query, $expected));
})->with([
    // Diacritics are folded (remove_diacritics 2 / utf8mb4_unicode_ci): what a
    // person types without a Swedish keyboard finds the Swedish record.
    'customers · goteborg finds Göteborg' => ['customers', 'goteborg', ['01KFDA3QM02VDHVC2WGADBB9BV']],
    'customers · nordlund' => ['customers', 'nordlund', ['01M4FJ7ZJRDVPBT5PEZTZWC6BX']],
    // Both halves of the §5.6 shared-domain pair, found by what the text says
    // rather than by the domain they share.
    'customers · studio' => ['customers', 'studio', ['01KKGMCCM0YSR4Q6TVT46VQE03', '01KS26C4008VE6T87ZXRF76DRK']],
    'customers · prefix ferrar*' => ['customers', 'ferrar*', ['01KKGMCCM0YSR4Q6TVT46VQE03', '01KS26C4008VE6T87ZXRF76DRK']],
    'customers · every term must match' => ['customers', 'lagerlokal molndal', ['01M4FJ7ZJRDVPBT5PEZTZWC6BX']],
    'customers · a term in no indexed column misses' => ['customers', 'lagerlokal goteborg', []],
    // tags and email_domains are JSON: InnoDB cannot index them, so neither side does.
    'customers · json columns are not indexed' => ['customers', 'milano', []],
    'contacts · sjostrom finds Sjöström' => ['contacts', 'sjostrom', ['01KFDACWK0EPRYZ6GG2ZV1HQ2V']],
    'contacts · conti' => ['contacts', 'conti', ['01KS26Q3J0TVZ3NVFNY9VKTND0']],
    'contacts · torino in a note' => ['contacts', 'torino', ['01KS26N8Z0W4P6VTDN4AZP0GH6']],
    'contacts · a hyphenated name is two folded tokens' => ['contacts', 'brontë-çelik', ['01KXJCXGZ091ZFGNA99GFX5MZR']],
    // Addresses live in contact_emails, which has no index of its own.
    'contacts · addresses are not indexed' => ['contacts', 'muessig', []],
    'communications · molndal across a thread' => ['communications', 'molndal', ['01M4AK8VG09C6MDFWH89XZ6S15', '01M4DRWT001ZTKBKSA08R7V2K2']],
    'communications · akersberga' => ['communications', 'akersberga', ['01M4DMDZP0GRSEE84KCBFHRDSR']],
    'communications · consegna over mail and notes' => ['communications', 'consegna', ['01M4871WM0H41SXQDSBZKJ6K6P', '01M4E0J5J0CX5DFJHZJ0SX2QD6', '01M4E1MYV0QE5T3056BMKG4TFS']],
    'communications · the corrected match is findable' => ['communications', 'fattura', ['01M4DA4CT0TWVYX223RRGM18KF']],
    'communications · lagerverwaltung over mail, call and meeting' => ['communications', 'lagerverwaltung', ['01M3XXF0M017B5JR777ZABF9X3', '01M4D2D6N0TXS7MCCS19R5M18H', '01M4DVW0W0E5J42MVNK58V8ZMH']],
    'communications · subject and excerpt are one index' => ['communications', 'offert molndal', ['01M4AK8VG09C6MDFWH89XZ6S15', '01M4DRWT001ZTKBKSA08R7V2K2']],
])->group('backend-contract')->group('search')->skip(fn (): bool => BackendProfile::serverWithoutMysql(), 'server profile selected but no MySQL connection configured');

it('never returns a row from another organization', function (): void {
    withSearchCorpus(function (): void {
        $hits = app(FullTextSearch::class)->search(SearchableEntity::Customers, CrmFixturePack::organizationId(), 'nordlund');

        expect($hits)->toBe([]);
    });
})->group('backend-contract')->group('search')->skip(fn (): bool => BackendProfile::serverWithoutMysql(), 'server profile selected but no MySQL connection configured');

it('ignores quotes, punctuation and stray whitespace in what a person typed', function (): void {
    withSearchCorpus(function (): void {
        foreach (['"nordlund"', 'nordlund)', '  nordlund  ', 'nordlund, mölndal', '^nordlund*~'] as $query) {
            expectHits(SearchableEntity::Customers, $query, ['01M4FJ7ZJRDVPBT5PEZTZWC6BX']);
        }

        expect(app(FullTextSearch::class)->search(SearchableEntity::Customers, searchCorpusOrganizationId(), '   '))->toBe([]);
    });
})->group('backend-contract')->group('search')->skip(fn (): bool => BackendProfile::serverWithoutMysql(), 'server profile selected but no MySQL connection configured');

it('keeps the index consistent after insert, update and delete', function (): void {
    withSearchCorpus(function (): void {
        $organizationId = searchCorpusOrganizationId();
        $id = CrmFixturePack::mirrorId((string) Str::ulid());
        $search = app(FullTextSearch::class);

        DB::table('customers')->insert([
            'id' => $id,
            'organization_id' => $organizationId,
            'legal_name' => 'Kvarnholmen Entreprenad AB',
            'display_name' => 'Kvarnholmen Entreprenad',
            'country' => 'SE',
            'registration_number' => null,
            'email_domains' => '[]',
            'website' => null,
            'status' => 'prospect',
            'owner_user_id' => null,
            'tags' => '[]',
            'notes' => 'Index consistency probe.',
            'sensitivity_class' => 'internal',
            'provenance' => (string) json_encode(Provenance::job('CrmSearchContractTest', ProvenanceOrigin::Import)),
        ]);

        expect($search->search(SearchableEntity::Customers, $organizationId, 'kvarnholmen'))->toBe([$id]);

        DB::table('customers')->where('id', $id)->update([
            'legal_name' => 'Skeppsbron Entreprenad AB',
            'display_name' => 'Skeppsbron Entreprenad',
        ]);

        expect($search->search(SearchableEntity::Customers, $organizationId, 'skeppsbron'))->toBe([$id])
            ->and($search->search(SearchableEntity::Customers, $organizationId, 'kvarnholmen'))->toBe([]);

        DB::table('customers')->where('id', $id)->delete();

        expect($search->search(SearchableEntity::Customers, $organizationId, 'skeppsbron'))->toBe([]);
    });
})->group('backend-contract')->group('search')->skip(fn (): bool => BackendProfile::serverWithoutMysql(), 'server profile selected but no MySQL connection configured');

it('drops a soft-deleted row out of the results without touching the index', function (): void {
    withSearchCorpus(function (): void {
        $id = CrmFixturePack::mirrorId('01KFDA3QM02VDHVC2WGADBB9BV');

        DB::table('customers')->where('id', $id)->update(['deleted_at' => '2026-10-11 00:00:00']);

        expect(app(FullTextSearch::class)->search(SearchableEntity::Customers, searchCorpusOrganizationId(), 'goteborg'))->toBe([]);
    });
})->group('backend-contract')->group('search')->skip(fn (): bool => BackendProfile::serverWithoutMysql(), 'server profile selected but no MySQL connection configured');
