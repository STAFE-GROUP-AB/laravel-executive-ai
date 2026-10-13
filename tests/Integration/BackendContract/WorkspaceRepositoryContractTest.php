<?php

declare(strict_types=1);

use App\CRM\Communications\ActivitySource;
use App\CRM\Eloquent\CrmEntity;
use App\CRM\Eloquent\WorkspaceQueries;
use App\CRM\Exceptions\WorkspaceAccessDeniedException;
use App\CRM\Queries\ActivityQuery;
use App\CRM\Queries\ContactQuery;
use App\CRM\Queries\CustomerQuery;
use App\CRM\Queries\ProjectQuery;
use App\CRM\Queries\QueryPage;
use App\CRM\Queries\TaskQuery;
use App\CRM\WorkspaceRepository;
use App\CRM\WorkspaceScope;
use App\Models\Membership;
use App\Models\MembershipRole;
use App\Models\Organization;
use App\Models\User;
use App\Support\Cancellation\CancellationSource;
use App\Support\Cancellation\NeverCancelled;
use App\Support\Cancellation\OperationCancelledException;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BackendProfile;
use Tests\Support\CrmFixturePack;

uses(DatabaseTransactions::class);

/*
|--------------------------------------------------------------------------
| ADR-002 D10 `repository` group (S2-09, issue #91)
|--------------------------------------------------------------------------
|
| "Every `WorkspaceRepository` query (§3) over the frozen CRM fixture returns
| identical ids" — so the assertions below are **id lists**, taken from the
| committed S0-18 pack rather than from factories, and every test carries the
| `backend-contract` group so the `server-profile` lane runs it against MySQL
| as well. A repository that is right on SQLite and wrong on MySQL is a
| repository that is wrong in production.
|
| The ids are literal on purpose. A test that derived them from the same query
| it is testing would pass whatever the ordering is; a test that lists them
| fails when the order changes, which is the property the group exists for
| ("ordered by `id` ascending", module-contracts.md §3).
|
| Unlike `CrmSearchContractTest` this file **is** transactional: nothing here
| asks MySQL to publish a row to a FULLTEXT index, so the committed corpus is
| not needed and the pack's own ULIDs are used as they stand. The one search
| case is a negative — a term with no match must narrow to nothing — which is
| the same answer on both engines whether the index sees the rows or not.
|
*/

/** @return array{0: WorkspaceScope, 1: User, 2: Organization} the pack's workspace, with a membership so it can be read */
function packWorkspace(): array
{
    $pack = CrmFixturePack::import();

    /** @var string $organizationId */
    $organizationId = $pack['customers'][0]['organization_id'];
    /** @var string $ownerUserId */
    $ownerUserId = $pack['customers'][0]['owner_user_id'];

    $organization = Organization::query()->findOrFail($organizationId);
    $owner = User::query()->findOrFail($ownerUserId);

    // The pack deliberately creates no membership (it is a fixture, not an
    // install), and the repository refuses a scope without one — which is the
    // behaviour the cross-account test below relies on. So the membership is
    // the test's, created explicitly.
    $membership = Membership::query()
        ->where('organization_id', $organization->getKey())
        ->where('user_id', $owner->getKey())
        ->first();

    if ($membership === null) {
        Membership::factory()->create([
            'organization_id' => $organization->getKey(),
            'user_id' => $owner->getKey(),
            'role' => MembershipRole::Owner,
        ]);
    }

    return [new WorkspaceScope((string) $organization->getKey(), (string) $owner->getKey()), $owner, $organization];
}

function repository(): WorkspaceRepository
{
    return app(WorkspaceRepository::class);
}

/** @param iterable<int, object> $summaries */
function idsOf(iterable $summaries): array
{
    $ids = [];

    foreach ($summaries as $summary) {
        /** @var object{id: string} $summary */
        $ids[] = $summary->id;
    }

    return $ids;
}

it('returns the frozen customers by id ascending, identically on both profiles', function (): void {
    [$scope] = packWorkspace();

    $customers = repository()->customers(new CustomerQuery(scope: $scope), new NeverCancelled);

    expect(idsOf($customers))->toBe([
        '01KFDA3QM02VDHVC2WGADBB9BV',
        '01KKGMCCM0YSR4Q6TVT46VQE03',
        '01KS26C4008VE6T87ZXRF76DRK',
        '01KT67Y600Z18DMTCEW0G8CQ52',
        '01KXJCMC00D2RY518YA668KMCD',
        '01M4FJ7ZJRDVPBT5PEZTZWC6BX',
    ]);
})->group('backend-contract')->group('repository')->skip(fn (): bool => BackendProfile::serverWithoutMysql(), 'server profile selected but no MySQL connection configured');

it('projects a customer onto the read model the agent and the brief generator see', function (): void {
    [$scope] = packWorkspace();

    $customers = repository()->customers(
        new CustomerQuery(scope: $scope, ids: ['01KFDA3QM02VDHVC2WGADBB9BV']),
        new NeverCancelled,
    );

    $customer = $customers[0];

    expect($customer->id)->toBe('01KFDA3QM02VDHVC2WGADBB9BV')
        ->and($customer->organizationId)->toBe($scope->organizationId)
        ->and($customer->name)->not->toBe('')
        ->and($customer->legalIdentity)->not->toBe('')
        ->and($customer->status)->toBeString()
        ->and($customer->updatedAt)->toBeInstanceOf(DateTimeImmutable::class);
})->group('backend-contract')->group('repository')->skip(fn (): bool => BackendProfile::serverWithoutMysql(), 'server profile selected but no MySQL connection configured');

it('bounds a page and walks it by keyset, with no row seen twice or skipped', function (): void {
    [$scope] = packWorkspace();

    $first = idsOf(repository()->customers(new CustomerQuery(scope: $scope, page: new QueryPage(limit: 2)), new NeverCancelled));

    expect($first)->toBe(['01KFDA3QM02VDHVC2WGADBB9BV', '01KKGMCCM0YSR4Q6TVT46VQE03']);

    $second = idsOf(repository()->customers(
        new CustomerQuery(scope: $scope, page: new QueryPage(limit: 2, after: $first[1])),
        new NeverCancelled,
    ));

    expect($second)->toBe(['01KS26C4008VE6T87ZXRF76DRK', '01KT67Y600Z18DMTCEW0G8CQ52'])
        ->and(array_intersect($first, $second))->toBe([]);
})->group('backend-contract')->group('repository')->skip(fn (): bool => BackendProfile::serverWithoutMysql(), 'server profile selected but no MySQL connection configured');

it('returns the frozen contacts with every address and customer link', function (): void {
    [$scope] = packWorkspace();

    $contacts = repository()->contacts(new ContactQuery(scope: $scope), new NeverCancelled);

    expect(array_map(static fn (object $contact): string => $contact->id, $contacts))->toBe([
        '01KFDACWK0EPRYZ6GG2ZV1HQ2V',
        '01KH3CETM0PGFQ5HFHQ2A5DNM9',
        '01KKGMNHK0SAX1VQBKV6VXPST8',
        '01KS26N8Z0W4P6VTDN4AZP0GH6',
        '01KS26Q3J0TVZ3NVFNY9VKTND0',
        '01KT687AZ030JA890HTFR6138S',
        '01KXJCXGZ091ZFGNA99GFX5MZR',
        '01M4FJ7VNR32E7DH0T2MHMD75Q',
    ]);

    foreach ($contacts as $contact) {
        expect($contact->emailAddresses)->toBeArray();

        foreach ($contact->emailAddresses as $address) {
            expect($address)->toBe(mb_strtolower($address), 'addresses are stored and returned lower-cased');
        }

        // `customerIds` is a set in the schema, so the order is ours and must
        // be the same on both engines: ascending.
        $sorted = $contact->customerIds;
        sort($sorted);
        expect($contact->customerIds)->toBe($sorted);
    }
})->group('backend-contract')->group('repository')->skip(fn (): bool => BackendProfile::serverWithoutMysql(), 'server profile selected but no MySQL connection configured');

it('finds a contact by any of its addresses', function (): void {
    [$scope] = packWorkspace();

    $pack = CrmFixturePack::load();
    /** @var array{id: string, emails: list<array{address: string}>} $record */
    $record = $pack['contacts'][0];
    $address = $record['emails'][0]['address'];

    $found = idsOf(repository()->contacts(
        new ContactQuery(scope: $scope, emailAddress: mb_strtoupper($address)),
        new NeverCancelled,
    ));

    expect($found)->toBe([$record['id']], 'the lookup must be case-insensitive on the address');
})->group('backend-contract')->group('repository')->skip(fn (): bool => BackendProfile::serverWithoutMysql(), 'server profile selected but no MySQL connection configured');

it('returns open projects by default and every project when asked', function (): void {
    [$scope] = packWorkspace();

    $open = idsOf(repository()->projects(new ProjectQuery(scope: $scope), new NeverCancelled));
    $all = idsOf(repository()->projects(new ProjectQuery(scope: $scope, openOnly: false), new NeverCancelled));

    expect($open)->not->toBeEmpty()
        ->and($all)->toHaveCount(count(CrmFixturePack::load()['projects']))
        ->and(array_diff($open, $all))->toBe([])
        // Ascending by id, both times.
        ->and($all)->toBe(collect($all)->sort()->values()->all())
        ->and($open)->toBe(collect($open)->sort()->values()->all());
})->group('backend-contract')->group('repository')->skip(fn (): bool => BackendProfile::serverWithoutMysql(), 'server profile selected but no MySQL connection configured');

it('returns open tasks with their assignees, ordered by id', function (): void {
    [$scope, $owner] = packWorkspace();

    $tasks = repository()->tasks(new TaskQuery(scope: $scope), new NeverCancelled);
    $ids = array_map(static fn (object $task): string => $task->id, $tasks);

    expect($ids)->not->toBeEmpty()
        ->and($ids)->toBe(collect($ids)->sort()->values()->all());

    $assigned = idsOf(repository()->tasks(
        new TaskQuery(scope: $scope, assigneeUserId: (string) $owner->getKey()),
        new NeverCancelled,
    ));

    expect($assigned)->not->toBeEmpty('the pack assigns tasks to its owner')
        ->and(array_diff($assigned, $ids))->toBe([]);
})->group('backend-contract')->group('repository')->skip(fn (): bool => BackendProfile::serverWithoutMysql(), 'server profile selected but no MySQL connection configured');

it('returns the timeline newest first, anchored, and never unanchored', function (): void {
    [$scope] = packWorkspace();

    $entries = repository()->timeline(
        new ActivityQuery(scope: $scope, from: new DateTimeImmutable('2000-01-01T00:00:00Z')),
        new NeverCancelled,
    );

    expect($entries)->not->toBeEmpty();

    $previous = null;

    foreach ($entries as $entry) {
        expect($entry->organizationId)->toBe($scope->organizationId)
            ->and($entry->source)->toBeInstanceOf(ActivitySource::class)
            ->and($entry->sourceId)->not->toBe('');

        if ($previous !== null) {
            expect($entry->occurredAt <= $previous)->toBeTrue('the timeline is newest first');
        }

        $previous = $entry->occurredAt;
    }

    // The query object itself refuses to be "everything".
    expect(fn (): ActivityQuery => new ActivityQuery(scope: $scope))->toThrow(InvalidArgumentException::class);
})->group('backend-contract')->group('repository')->skip(fn (): bool => BackendProfile::serverWithoutMysql(), 'server profile selected but no MySQL connection configured');

it('refuses a scope whose actor has no membership, rather than answering with an empty page', function (): void {
    [$scope] = packWorkspace();
    $stranger = User::factory()->create();

    // This is the property that matters: the §11 cross-account tests must be
    // able to tell "nothing there" from "refused", so a foreign scope raises
    // and does not quietly return zero rows.
    $foreign = new WorkspaceScope($scope->organizationId, (string) $stranger->getKey());

    expect(fn (): array => idsOf(repository()->customers(new CustomerQuery(scope: $foreign), new NeverCancelled)))
        ->toThrow(WorkspaceAccessDeniedException::class);

    foreach ([
        fn (): iterable => repository()->contacts(new ContactQuery(scope: $foreign), new NeverCancelled),
        fn (): iterable => repository()->projects(new ProjectQuery(scope: $foreign), new NeverCancelled),
        fn (): iterable => repository()->tasks(new TaskQuery(scope: $foreign), new NeverCancelled),
        fn (): iterable => repository()->timeline(new ActivityQuery(scope: $foreign, from: new DateTimeImmutable('2000-01-01T00:00:00Z')), new NeverCancelled),
    ] as $read) {
        expect(fn (): array => idsOf($read()))->toThrow(WorkspaceAccessDeniedException::class);
    }
})->group('backend-contract')->group('repository')->skip(fn (): bool => BackendProfile::serverWithoutMysql(), 'server profile selected but no MySQL connection configured');

it('returns nothing for another organization\'s id, with no caller-side filtering', function (): void {
    [, $owner] = packWorkspace();

    $other = Organization::factory()->create();
    Membership::factory()->create([
        'organization_id' => $other->getKey(),
        'user_id' => $owner->getKey(),
        'role' => MembershipRole::Member,
    ]);

    // The actor *is* a member of `$other`, so the scope is legitimate — and
    // the pack's rows still do not appear, because the predicate is applied
    // where the query is built and not by a caller that remembered to add it.
    $scope = new WorkspaceScope((string) $other->getKey(), (string) $owner->getKey());

    expect(idsOf(repository()->customers(new CustomerQuery(scope: $scope), new NeverCancelled)))->toBe([])
        ->and(idsOf(repository()->contacts(new ContactQuery(scope: $scope), new NeverCancelled)))->toBe([])
        ->and(idsOf(repository()->projects(new ProjectQuery(scope: $scope, openOnly: false), new NeverCancelled)))->toBe([])
        ->and(idsOf(repository()->tasks(new TaskQuery(scope: $scope, openOnly: false), new NeverCancelled)))->toBe([]);

    // And the same id put to a specific row: a scope naming organization B
    // cannot reach a row of organization A even by id.
    expect(idsOf(repository()->customers(
        new CustomerQuery(scope: $scope, ids: ['01KFDA3QM02VDHVC2WGADBB9BV']),
        new NeverCancelled,
    )))->toBe([]);
})->group('backend-contract')->group('repository')->skip(fn (): bool => BackendProfile::serverWithoutMysql(), 'server profile selected but no MySQL connection configured');

it('scopes the query at the boundary: the builder carries the predicate before a caller sees it', function (): void {
    [$scope] = packWorkspace();

    $queries = app(WorkspaceQueries::class);

    foreach (CrmEntity::cases() as $entity) {
        // The identifier quote differs per engine (`"` on SQLite, backticks on
        // MySQL), and the point of the assertion is the *column*, so the quotes
        // are removed before looking.
        $sql = str_replace(['"', '`'], '', $queries->of($scope, $entity)->toSql());

        expect($sql)->toContain($entity->table().'.organization_id');
    }

    // And there is no way to get a builder for an organization the scope may
    // not read: the check happens inside `of()`, before the builder exists.
    $stranger = User::factory()->create();

    expect(fn () => $queries->of(new WorkspaceScope($scope->organizationId, (string) $stranger->getKey()), CrmEntity::Customers))
        ->toThrow(WorkspaceAccessDeniedException::class);
})->group('backend-contract')->group('repository')->skip(fn (): bool => BackendProfile::serverWithoutMysql(), 'server profile selected but no MySQL connection configured');

it('never returns a soft-deleted row', function (): void {
    [$scope] = packWorkspace();

    DB::table('customers')
        ->where('id', '01KFDA3QM02VDHVC2WGADBB9BV')
        ->update(['deleted_at' => CarbonImmutable::now()->toDateTimeString()]);

    expect(idsOf(repository()->customers(new CustomerQuery(scope: $scope), new NeverCancelled)))
        ->not->toContain('01KFDA3QM02VDHVC2WGADBB9BV');
})->group('backend-contract')->group('repository')->skip(fn (): bool => BackendProfile::serverWithoutMysql(), 'server profile selected but no MySQL connection configured');

it('answers a term with no match with nothing, never with the whole table', function (): void {
    [$scope] = packWorkspace();

    // A failed search that left the builder untouched would answer with every
    // customer in the workspace, which is the worst possible answer: it looks
    // like a result. The same on both engines, index state aside.
    expect(idsOf(repository()->customers(
        new CustomerQuery(scope: $scope, search: 'zzzznothingmatchesthis'),
        new NeverCancelled,
    )))->toBe([]);
})->group('backend-contract')->group('repository')->skip(fn (): bool => BackendProfile::serverWithoutMysql(), 'server profile selected but no MySQL connection configured');

it('observes the cancellation token before it reads', function (): void {
    [$scope] = packWorkspace();

    $source = new CancellationSource;
    $source->cancel('the user pressed stop');

    expect(fn (): array => idsOf(repository()->customers(new CustomerQuery(scope: $scope), $source->token())))
        ->toThrow(OperationCancelledException::class);
})->group('backend-contract')->group('repository')->skip(fn (): bool => BackendProfile::serverWithoutMysql(), 'server profile selected but no MySQL connection configured');

it('refuses a scope whose actor no longer exists', function (): void {
    [$scope] = packWorkspace();

    $gone = new WorkspaceScope($scope->organizationId, (string) Str::ulid());

    expect(fn (): array => idsOf(repository()->customers(new CustomerQuery(scope: $gone), new NeverCancelled)))
        ->toThrow(WorkspaceAccessDeniedException::class);
})->group('backend-contract')->group('repository')->skip(fn (): bool => BackendProfile::serverWithoutMysql(), 'server profile selected but no MySQL connection configured');
