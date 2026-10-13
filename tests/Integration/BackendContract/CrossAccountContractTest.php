<?php

declare(strict_types=1);

use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\MembershipRole;
use App\Models\Organization;
use App\Models\User;
use App\Security\Audit\AuditLog;
use App\Security\Authorization\Gates\Abilities;
use App\Security\Authorization\Gates\AuthorizationAuditor;
use App\Security\Authorization\Gates\Surface;
use App\Security\Authorization\Gates\SurfaceContext;
use App\Security\Authorization\Gates\TenantRowGuard;
use App\Security\Authorization\Gates\ToolAuthorizer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Security\Support\Surfaces\CustomerNotesTool;
use Tests\Security\Support\Surfaces\GuardedTenantJob;
use Tests\Security\Support\TenantRowFactory;
use Tests\Support\BackendProfile;
use Tests\Support\BriefFixtures;

use function Pest\Laravel\actingAs;

uses(DatabaseTransactions::class);

/*
|--------------------------------------------------------------------------
| The `cross-account` group (S2-06, issue #88; threat model T-73)
|--------------------------------------------------------------------------
|
| ADR-002 D10 names this group as the one that runs on **both** backend
| profiles — `local` (SQLite in WAL mode) and `server` (MySQL 8.4) — and §5.6
| acceptance criterion 4 states the requirement it carries: automated
| **negative** tests proving that mail, rows and actions belonging to one
| account cannot be reached through another, across all five paths T-73 names:
| the API, a Livewire action, an agent, a queued job and an MCP tool.
|
| Why it has to run on both: the tenant boundary is `organization_id` plus the
| policy that reads it, and the two engines differ in exactly the places that
| could quietly change the answer — collation and case sensitivity when
| comparing a ULID, `NULL` handling in the membership join, and whether a
| foreign key is enforced at all. A policy that is right on SQLite and wrong on
| MySQL is a policy that is wrong in production.
|
| The provider half of T-73 — mail actually fetched or sent through the wrong
| account at a real provider — stays with **E3-1**: there is no live connector
| in Sprint 2. What is testable now is everything on this side of the
| connector, which is where the IDOR would be.
|
| `tests/Security/IdorTest.php` is the same boundary in more depth on the
| `local` profile; this file is the part ADR-002 D10 requires to hold on both.
|
*/

$skip = fn (): bool => BackendProfile::serverWithoutMysql();
$reason = 'server profile selected but no MySQL connection configured';

/** @return array{0: User, 1: User, 2: Organization, 3: Organization, 4: TenantRowFactory, 5: TenantRowFactory} */
function crossAccountFixture(): array
{
    $accountA = Organization::factory()->create();
    $accountB = Organization::factory()->create();

    $userA = User::factory()->create();
    $userB = User::factory()->create();

    // The owner role on both sides: §11's "no admin bypass" means the strongest
    // role in account A is still refused every row of account B.
    Membership::factory()->create(['organization_id' => $accountA->id, 'user_id' => $userA->id, 'role' => MembershipRole::Owner]);
    Membership::factory()->create(['organization_id' => $accountB->id, 'user_id' => $userB->id, 'role' => MembershipRole::Owner]);

    return [
        $userA,
        $userB,
        $accountA,
        $accountB,
        new TenantRowFactory($accountA, User::factory()->create()),
        new TenantRowFactory($accountB, User::factory()->create()),
    ];
}

function crossAccountRefusals(): int
{
    return AuditEvent::query()
        ->where('action', 'like', AuthorizationAuditor::ACTION_PREFIX.'%')
        ->where('outcome', AuditLog::OUTCOME_REFUSED)
        ->count();
}

it('refuses account B every row of account A, on every table that carries organization_id', function (): void {
    [$userA, $userB, , , $rowsOfA] = crossAccountFixture();

    $gate = app(Gate::class);
    $guard = app(TenantRowGuard::class);
    $models = TenantRowFactory::modelsByTable();
    $tables = TenantRowFactory::tenantTables();

    expect($tables)->not->toBeEmpty();

    foreach ($tables as $table) {
        $id = $rowsOfA->insert($table);

        if (! isset($models[$table])) {
            expect($guard->allows($userB, $table, $id))->toBeFalse("{$table}: account B reached a row of account A")
                ->and($guard->allows($userA, $table, $id))->toBeTrue("{$table}: account A was refused its own row");

            continue;
        }

        $row = $rowsOfA->model($table);

        expect($gate->forUser($userB)->allows(Abilities::VIEW, $row))->toBeFalse("{$table}: account B read a row of account A")
            ->and($gate->forUser($userB)->allows(Abilities::UPDATE, $row))->toBeFalse("{$table}: account B could write a row of account A")
            ->and($gate->forUser($userB)->allows(Abilities::DELETE, $row))->toBeFalse("{$table}: account B could delete a row of account A")
            ->and($gate->forUser($userA)->allows(Abilities::VIEW, $row))->toBeTrue("{$table}: account A was refused its own row");
    }
})->group('backend-contract', 'cross-account')->skip($skip, $reason);

it('keeps a mail account, its cursor and its communications inside the organization they belong to', function (): void {
    [$userA, $userB, , , $rowsOfA, $rowsOfB] = crossAccountFixture();

    $gate = app(Gate::class);
    $guard = app(TenantRowGuard::class);

    // §5.6: separate `email_connections` per user/organization/provider, each
    // with its own credential, cursor, rate limit and policy. The policy half
    // is this: the id of A's connection is useless to B, and so is the id of
    // the cursor that belongs to it.
    foreach (['email_connections', 'email_sync_cursors', 'external_accounts', 'sync_cursors'] as $table) {
        $ofA = $rowsOfA->insert($table);
        $ofB = $rowsOfB->insert($table);

        expect($guard->allows($userB, $table, $ofA))->toBeFalse("{$table}: B reached A's row")
            ->and($guard->allows($userA, $table, $ofB))->toBeFalse("{$table}: A reached B's row")
            ->and($guard->allows($userA, $table, $ofA))->toBeTrue("{$table}: A was refused its own row")
            ->and($guard->allows($userB, $table, $ofB))->toBeTrue("{$table}: B was refused its own row");
    }

    // And the mail that was fetched through it: a communication, the commitment
    // extracted from it, and the contact address it arrived at.
    foreach (['communications', 'commitments', 'contact_emails', 'contacts'] as $table) {
        $row = $rowsOfA->model($table);

        expect($gate->forUser($userB)->allows(Abilities::VIEW, $row))->toBeFalse("{$table}: B read mail belonging to A")
            ->and($gate->forUser($userA)->allows(Abilities::VIEW, $row))->toBeTrue("{$table}: A was refused its own mail");
    }
})->group('backend-contract', 'cross-account')->skip($skip, $reason);

it('refuses the crossing on all five paths, and records each refusal', function (): void {
    [$userA, $userB, , $accountB, $rowsOfA] = crossAccountFixture();

    $gate = app(Gate::class);
    $guard = app(TenantRowGuard::class);
    $surfaces = app(SurfaceContext::class);

    $customerOfA = $rowsOfA->model('customers');
    $briefOfA = BriefFixtures::import('canonical', Organization::query()->findOrFail($customerOfA->getAttribute('organization_id')));
    $mcpToolOfA = $rowsOfA->insert('mcp_tools');

    $before = crossAccountRefusals();

    // 1. the API / HTTP surface
    actingAs($userB)->get('/briefs/'.$briefOfA->getKey())->assertForbidden();

    // 2. a Livewire action — the same gate, on the same surface, which is what
    //    "the PWA and the IPC run the same gate" means in practice (§11.1).
    $surfaces->within(Surface::Pwa, function () use ($gate, $userB, $customerOfA): void {
        expect($gate->forUser($userB)->allows(Abilities::UPDATE, $customerOfA))->toBeFalse('a Livewire action could write across accounts');
    });

    // 3. an agent / AI tool
    expect(fn () => app(ToolAuthorizer::class)->authorize($userB, new CustomerNotesTool, ['customer_id' => (string) $customerOfA->getKey()]))
        ->toThrow(AuthorizationException::class);

    // 4. a queued job
    expect(fn () => (new GuardedTenantJob((string) $userB->id, (string) $customerOfA->getKey()))->handle())
        ->toThrow(AuthorizationException::class);

    // 5. an MCP tool reaching the registry row directly
    expect(fn () => $guard->authorizeOn(Surface::Tool, $userB, 'mcp_tools', $mcpToolOfA))
        ->toThrow(AuthorizationException::class);

    // Each of the five left a row: T-46's reason for existing, on both profiles.
    expect(crossAccountRefusals())->toBeGreaterThanOrEqual($before + 5, 'every refusal on every path is on the audit record');

    $surfacesSeen = AuditEvent::query()
        ->where('action', 'like', AuthorizationAuditor::ACTION_PREFIX.'%')
        ->where('outcome', AuditLog::OUTCOME_REFUSED)
        ->get()
        ->map(fn (AuditEvent $event): mixed => $event->context['surface'] ?? null)
        ->unique()
        ->values()
        ->all();

    expect($surfacesSeen)->toContain(Surface::Pwa->value, Surface::Job->value, Surface::Tool->value);

    // Nothing was written: the refusal is not an after-the-fact detection.
    expect($customerOfA->fresh()?->getAttribute('notes'))->not->toBe('touched by a job')
        ->and($accountB->id)->not->toBe($customerOfA->getAttribute('organization_id'));
})->group('backend-contract', 'cross-account')->skip($skip, $reason);

it('compares the tenant id exactly, whatever the engine collates', function (): void {
    [$userA, , $accountA, , $rowsOfA] = crossAccountFixture();

    $gate = app(Gate::class);
    $customer = $rowsOfA->model('customers');
    $organizationId = (string) $accountA->id;

    expect($gate->forUser($userA)->allows(Abilities::VIEW, $customer))->toBeTrue();

    // A ULID differs from its neighbours by one character, and MySQL's default
    // collation is case-insensitive while SQLite's BINARY is not. A membership
    // lookup that lost either property would let a near-miss id through, so the
    // mutations are asserted rather than assumed on both profiles.
    foreach ([strtolower($organizationId), substr($organizationId, 0, -1).'Z', substr($organizationId, 1).'0'] as $nearMiss) {
        if ($nearMiss === $organizationId) {
            continue;
        }

        $impostor = $rowsOfA->model('customers')->replicate();
        $impostor->setAttribute('organization_id', $nearMiss);

        expect($gate->forUser($userA)->allows(Abilities::VIEW, $impostor))->toBeFalse(
            "a row whose organization_id is `{$nearMiss}` is not a row of `{$organizationId}`",
        );
    }
})->group('backend-contract', 'cross-account')->skip($skip, $reason);

it('refuses a user whose membership was revoked, on both engines', function (): void {
    [$userA, , $accountA, , $rowsOfA] = crossAccountFixture();

    $gate = app(Gate::class);
    $customer = $rowsOfA->model('customers');

    expect($gate->forUser($userA)->allows(Abilities::VIEW, $customer))->toBeTrue();

    Membership::query()->where('user_id', $userA->id)->where('organization_id', $accountA->id)->delete();

    expect($gate->forUser($userA)->allows(Abilities::VIEW, $customer))->toBeFalse('a soft-deleted membership must not satisfy the tenant check');
})->group('backend-contract', 'cross-account')->skip($skip, $reason);
