<?php

declare(strict_types=1);

use App\Models\Membership;
use App\Models\MembershipRole;
use App\Models\Organization;
use App\Models\User;
use App\Security\Authorization\Gates\Abilities;
use App\Security\Authorization\Gates\TenantRowGuard;
use App\Security\Authorization\Tenancy;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\Security\Support\TenantRowFactory;
use Tests\Support\BriefFixtures;

use function Pest\Laravel\actingAs;

uses(DatabaseTransactions::class);

/*
|--------------------------------------------------------------------------
| IDOR and multi-tenant cross-access (S2-06, issue #88; threat model T-73)
|--------------------------------------------------------------------------
|
| The negative half of §11: a member of organization A must not reach a single
| row of organization B by guessing, reading or being handed its identifier.
|
| The sweep is driven by the **schema**, not by a list: every table that
| carries `organization_id` gets a real row in organization B, built by
| `TenantRowFactory` from the columns and foreign keys the migrations declared,
| and the member of A is refused it. A table added in Sprint 3 is therefore
| covered the day its migration runs — and if its shape defeats the factory,
| the sweep fails loudly rather than quietly skipping it.
|
| Three principals, because the interesting refusals are not about being a
| stranger:
|
| - `$memberOfA`  — a normal member of the other organization;
| - `$ownerOfA`   — the **owner** role in A, to prove there is no admin bypass:
|                   a role widens which organizations are visible, never which
|                   rules apply (`docs/architecture/pwa.md` §2);
| - `$memberOfB`  — the legitimate member, so every refusal below is shown to
|                   be about the tenant boundary and not about a policy that
|                   refuses everyone.
|
*/

/** @return array{0: User, 1: User, 2: User, 3: Organization, 4: Organization} */
function idorPrincipals(): array
{
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();

    $memberOfA = User::factory()->create();
    $ownerOfA = User::factory()->create();
    $memberOfB = User::factory()->create();

    Membership::factory()->create(['organization_id' => $organizationA->id, 'user_id' => $memberOfA->id, 'role' => MembershipRole::Member]);
    Membership::factory()->create(['organization_id' => $organizationA->id, 'user_id' => $ownerOfA->id, 'role' => MembershipRole::Owner]);
    Membership::factory()->create(['organization_id' => $organizationB->id, 'user_id' => $memberOfB->id, 'role' => MembershipRole::Member]);

    return [$memberOfA, $ownerOfA, $memberOfB, $organizationA, $organizationB];
}

/** The factory's `user_id` filler is nobody the test reasons about — see TenantRowFactory. */
function idorRowsIn(Organization $organization): TenantRowFactory
{
    return new TenantRowFactory($organization, User::factory()->create());
}

it('refuses every row of another organization, on read, write and delete, for every table in the schema', function (): void {
    [$memberOfA, $ownerOfA, $memberOfB, , $organizationB] = idorPrincipals();

    $gate = app(Gate::class);
    $guard = app(TenantRowGuard::class);
    $rows = idorRowsIn($organizationB);
    $models = TenantRowFactory::modelsByTable();
    $tables = TenantRowFactory::tenantTables();

    expect($tables)->not->toBeEmpty('the schema has no tenant tables — the sweep would assert nothing');

    foreach ($tables as $table) {
        $id = $rows->insert($table);

        if (! isset($models[$table])) {
            // No Eloquent model yet, so no policy to discover: the row is read
            // through TenantRowGuard, which asks the same question of the
            // row's own organization_id.
            expect($guard->allows($memberOfA, $table, $id))->toBeFalse("{$table}: a member of another organization reached row {$id}")
                ->and($guard->allows($ownerOfA, $table, $id))->toBeFalse("{$table}: the owner role bypassed the tenant check")
                ->and($guard->allows($memberOfB, $table, $id))->toBeTrue("{$table}: the legitimate member was refused their own row");

            continue;
        }

        $row = $rows->model($table);

        foreach ([Abilities::VIEW, Abilities::UPDATE, Abilities::DELETE] as $ability) {
            expect($gate->forUser($memberOfA)->allows($ability, $row))->toBeFalse("{$table}: `{$ability}` allowed across the tenant boundary")
                ->and($gate->forUser($ownerOfA)->allows($ability, $row))->toBeFalse("{$table}: the owner role bypassed `{$ability}`");
        }

        expect($gate->forUser($memberOfB)->allows(Abilities::VIEW, $row))->toBeTrue("{$table}: the legitimate member cannot read their own row");
    }
})->group('security', 'cross-account');

it('refuses a hard delete to everybody, on every table', function (): void {
    [, , $memberOfB, , $organizationB] = idorPrincipals();

    $gate = app(Gate::class);
    $rows = idorRowsIn($organizationB);
    $models = TenantRowFactory::modelsByTable();

    foreach (TenantRowFactory::tenantTables() as $table) {
        if (! isset($models[$table])) {
            continue;
        }

        expect($gate->forUser($memberOfB)->allows(Abilities::FORCE_DELETE, $rows->model($table)))
            ->toBeFalse("{$table}: forceDelete destroys the row's retention evidence and is nobody's decision");
    }
})->group('security', 'cross-account');

it('refuses an id that does not exist exactly as it refuses a foreign one, so ids cannot be probed', function (): void {
    [$memberOfA, , , , $organizationB] = idorPrincipals();

    $guard = app(TenantRowGuard::class);
    $rows = idorRowsIn($organizationB);
    $models = TenantRowFactory::modelsByTable();

    foreach (TenantRowFactory::tenantTables() as $table) {
        if (isset($models[$table])) {
            continue;
        }

        $foreign = $rows->insert($table);
        $absent = (string) Str::ulid();

        expect($guard->allows($memberOfA, $table, $foreign))->toBe(
            $guard->allows($memberOfA, $table, $absent),
            "{$table}: a foreign row and an absent row must be the same refusal, or the refusal tells the caller which ids exist",
        );
    }
})->group('security', 'cross-account');

it('is a 403 on every HTTP read of a brief from another organization, by version id and by document id', function (): void {
    [$memberOfA, $ownerOfA, $memberOfB, , $organizationB] = idorPrincipals();

    // A real fixture brief rather than a schema-built row, because the positive
    // leg renders the page: the refusals below must be the policy's, and the
    // only way to show that is for the same URLs to return 200 for the member
    // of the owning organization.
    $brief = BriefFixtures::import('canonical', $organizationB);
    $versionId = (string) $brief->getKey();
    $documentId = (string) $brief->document_id;

    $paths = [
        "/briefs/{$versionId}",
        "/briefs/{$versionId}/parity.json",
        "/briefs/{$versionId}/preview/mail",
        "/briefs/{$versionId}/preview/text",
        "/briefs/{$documentId}",
        "/briefs/{$documentId}/parity.json",
    ];

    foreach ([$memberOfA, $ownerOfA] as $intruder) {
        foreach ($paths as $path) {
            actingAs($intruder)->get($path)->assertForbidden();
        }
    }

    // Not a policy that refuses everyone: the owning organization's member
    // reads the same rows through the same routes.
    actingAs($memberOfB)->get("/briefs/{$versionId}")->assertOk();
    actingAs($memberOfB)->get("/briefs/{$documentId}/parity.json")->assertOk();
})->group('security', 'cross-account');

it('never trusts an id in the request for identity', function (): void {
    [$memberOfA, , $memberOfB, $organizationA, $organizationB] = idorPrincipals();

    $brief = BriefFixtures::import('canonical', $organizationB);
    $path = '/briefs/'.$brief->getKey();

    // ipc-contract §11.4 applied to the PWA: the owner binding comes from the
    // session, never from request content. A field that names the "right"
    // organization, user or membership changes nothing.
    $spoofs = [
        ['organization_id' => (string) $organizationB->id],
        ['organization_id' => (string) $organizationA->id],
        ['user_id' => (string) $memberOfB->id],
        ['owner' => (string) $memberOfB->id],
        ['approver' => (string) $memberOfB->id],
        ['membership' => 'owner'],
        ['role' => 'owner'],
    ];

    foreach ($spoofs as $spoof) {
        actingAs($memberOfA)->get($path.'?'.http_build_query($spoof))->assertForbidden();
    }

    // And the session's own user is what the gate reads: nothing in the query
    // string made the member of A into the member of B.
    expect(Tenancy::organizationIdsFor($memberOfA))->toBe([(string) $organizationA->id]);
})->group('security', 'cross-account');

it('refuses a row whose organization is null or empty rather than treating it as global', function (): void {
    [$memberOfA] = idorPrincipals();

    $gate = app(Gate::class);
    $orphan = new class extends Model
    {
        protected $table = 'customers';
    };

    $orphan->forceFill(['id' => (string) Str::ulid(), 'organization_id' => null]);

    expect(Tenancy::isMember($memberOfA, null))->toBeFalse()
        ->and(Tenancy::isMember($memberOfA, ''))->toBeFalse()
        ->and($gate->forUser($memberOfA)->allows(Abilities::VIEW, $orphan))->toBeFalse('a row with no organization belongs to nobody');
})->group('security', 'cross-account');

it('refuses a user with no membership at all, on every table', function (): void {
    [, , , , $organizationB] = idorPrincipals();

    $stranger = User::factory()->create();
    $gate = app(Gate::class);
    $rows = idorRowsIn($organizationB);
    $models = TenantRowFactory::modelsByTable();

    foreach (TenantRowFactory::tenantTables() as $table) {
        if (! isset($models[$table])) {
            continue;
        }

        expect($gate->forUser($stranger)->allows(Abilities::VIEW, $rows->model($table)))->toBeFalse("{$table}: a user with no membership read a row");
    }

    expect($gate->forUser($stranger)->allows(Abilities::VIEW_ANY, null))->toBeFalse('viewAny needs at least one membership');
})->group('security', 'cross-account');

it('stops being allowed the moment the membership is revoked', function (): void {
    [, , $memberOfB, , $organizationB] = idorPrincipals();

    $gate = app(Gate::class);
    $brief = BriefFixtures::import('canonical', $organizationB);

    expect($gate->forUser($memberOfB)->allows(Abilities::VIEW, $brief))->toBeTrue();

    Membership::query()->where('user_id', $memberOfB->id)->where('organization_id', $organizationB->id)->delete();

    expect($gate->forUser($memberOfB)->allows(Abilities::VIEW, $brief))->toBeFalse('a soft-deleted membership is still a membership somewhere');

    actingAs($memberOfB)->get('/briefs/'.$brief->getKey())->assertForbidden();
})->group('security', 'cross-account');

it('refuses an update or a delete on an append-only table even to the owning organization', function (): void {
    [, , $memberOfB, , $organizationB] = idorPrincipals();

    $gate = app(Gate::class);
    $rows = idorRowsIn($organizationB);

    foreach (['ai_runs', 'tool_runs', 'audit_events', 'action_approvals', 'brief_deliveries', 'source_records', 'memory_sources'] as $table) {
        $row = $rows->model($table);

        expect($gate->forUser($memberOfB)->allows(Abilities::VIEW, $row))->toBeTrue("{$table} must stay readable to its own organization")
            ->and($gate->forUser($memberOfB)->allows(Abilities::UPDATE, $row))->toBeFalse("{$table} is evidence and must not be updatable")
            ->and($gate->forUser($memberOfB)->allows(Abilities::DELETE, $row))->toBeFalse("{$table} is evidence and must not be deletable");
    }
})->group('security', 'cross-account');

it('refuses an update to a frozen proposal and allows one before the freeze', function (): void {
    [, , $memberOfB, , $organizationB] = idorPrincipals();

    $gate = app(Gate::class);
    $proposal = idorRowsIn($organizationB)->model('action_proposals');

    expect($proposal->getAttribute('frozen_at'))->not->toBeNull('the factory built an unfrozen proposal; this case needs a frozen one')
        ->and($gate->forUser($memberOfB)->allows(Abilities::VIEW, $proposal))->toBeTrue()
        ->and($gate->forUser($memberOfB)->allows(Abilities::UPDATE, $proposal))->toBeFalse('a frozen proposal is immutable (ADR-005 D1.6)');

    $proposal->setAttribute('frozen_at', null);

    expect($gate->forUser($memberOfB)->allows(Abilities::UPDATE, $proposal))->toBeTrue('before the freeze the policy is the ordinary tenant rule');
})->group('security', 'cross-account');
