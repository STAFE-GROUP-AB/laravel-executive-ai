<?php

declare(strict_types=1);

use App\Approvals\Policies\PolicyGate;
use App\Approvals\Policies\PolicyJournal;
use App\Approvals\Policies\ProposedAction;
use App\Approvals\Policies\Rules\OutgoingEmailRule;
use App\Approvals\Policies\ToolCallContext;
use App\Livewire\Auth\Devices;
use App\Models\AI\ToolRun;
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
use App\Security\Authorization\Maintenance\MaintenanceActor;
use App\Security\Authorization\Maintenance\TenantWideMaintenance;
use App\Security\Authorization\OwnerAbilities;
use App\Security\Secrets\AppKeyRotation;
use App\Support\Data\ProvenanceOrigin;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Security\Support\Surfaces\CustomerNotesTool;
use Tests\Security\Support\Surfaces\GuardedTenantJob;
use Tests\Security\Support\TenantRowFactory;
use Tests\Support\BriefFixtures;
use Tests\Support\Ipc\IpcClient;
use Tests\Support\PwaFixtures;

use function Pest\Laravel\actingAs;

uses(DatabaseTransactions::class);

/*
|--------------------------------------------------------------------------
| AuditCompletenessTest, per surface (S2-06, issue #88; threat model T-46)
|--------------------------------------------------------------------------
|
| The audit row is the evidence that the check ran. T-46 is honest about why
| that matters more here than elsewhere: `audit_events` is a table in the same
| SQLite file the core writes, so it is **not tamper-evident** in v1 (RR-05),
| and what the design offers instead of integrity is *breadth* — a row for
| every refusal and every mutating action, on every surface. A refusal that
| leaves no row is a refusal nobody can review, which is the same as a refusal
| that never happened.
|
| So there is a leg below for each of the five paths T-73 names, plus the
| console, and each asserts the same four things about the row: it exists, it
| names the **actor**, it names the **ability**, and it records the outcome as
| a refusal rather than dropping it. The `surface` in the context is what makes
| the rows readable afterwards: "the check ran" and "the check ran on the path
| the attacker used" are different facts.
|
| The row carries references only — an ability, a surface, a class name, a
| ULID. Never a payload, never brief content, never an image path (ADR-003 D7
| row 8); the last two cases in this file assert that, and `AuditNoBlobTest`
| keeps the table itself free of blob and path columns.
|
| **The sixth actor shape (#127).** Those five surfaces all have a user. One
| category deliberately does not — tenant-wide maintenance, authorised by not
| being a user action (`TenantWideMaintenance`,
| `docs/architecture/authorization.md` §10). Its rows land in the same table, so
| they are asserted here rather than in a second audit test: a trail in which
| "the system did this with nobody asking" cannot be told from "a user did this"
| answers the wrong question, and that distinction is the whole reason the
| category has its own actor shape instead of reusing `job:`.
|
*/

/** @return array{0: User, 1: User, 2: Organization, 3: Organization} */
function auditPrincipals(): array
{
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();

    $intruder = User::factory()->create();
    $member = User::factory()->create();

    Membership::factory()->create(['organization_id' => $organizationA->id, 'user_id' => $intruder->id, 'role' => MembershipRole::Owner]);
    Membership::factory()->create(['organization_id' => $organizationB->id, 'user_id' => $member->id, 'role' => MembershipRole::Member]);

    return [$intruder, $member, $organizationA, $organizationB];
}

/** @return Collection<int, AuditEvent> */
function authzEvents(?string $ability = null): Collection
{
    $query = AuditEvent::query()->where('action', 'like', AuthorizationAuditor::ACTION_PREFIX.'%');

    if ($ability !== null) {
        $query->where('action', AuthorizationAuditor::ACTION_PREFIX.$ability);
    }

    return $query->orderBy('occurred_at')->get();
}

function latestAuthzEvent(string $ability): AuditEvent
{
    $event = authzEvents($ability)->last();

    expect($event)->not->toBeNull("no audit_events row for `{$ability}`: the refusal was dropped (T-46)");

    return $event;
}

/**
 * The four facts every leg asserts, so a new surface added later has one call
 * to make rather than a shape to re-derive.
 */
function expectRefusalRecorded(AuditEvent $event, User $actor, string $ability, Surface $surface): void
{
    expect($event->action)->toBe(AuthorizationAuditor::ACTION_PREFIX.$ability)
        ->and($event->actor)->toBe("user:{$actor->id}", 'the row must name the actor')
        ->and($event->outcome)->toBe(AuditLog::OUTCOME_REFUSED, 'a refusal must be recorded as a refusal')
        ->and($event->context)->toHaveKey('ability')
        ->and($event->context['ability'] ?? null)->toBe($ability, 'the row must name the ability')
        ->and($event->context['surface'] ?? null)->toBe($surface->value, 'the row must name the surface the check ran on')
        ->and($event->context['result'] ?? null)->toBe('refused');
}

it('records the refusal on the PWA surface, with the actor, the ability and the row', function (): void {
    [$intruder, , , $organizationB] = auditPrincipals();

    $brief = BriefFixtures::import('canonical', $organizationB);

    actingAs($intruder)->get('/briefs/'.$brief->getKey())->assertForbidden();

    $event = latestAuthzEvent(Abilities::VIEW);

    expectRefusalRecorded($event, $intruder, Abilities::VIEW, Surface::Pwa);

    expect($event->subject_type)->toBe($brief::class, 'the row must name what was refused')
        ->and($event->subject_id)->toBe((string) $brief->getKey())
        ->and($event->organization_id)->toBe((string) $organizationB->id, 'the row must name the organization the refusal was about');
})->group('security');

it('records the refusal on the IPC surface, through the same gate the PWA runs', function (): void {
    $owner = PwaFixtures::owner();
    PwaFixtures::placeholder(Organization::factory()->create());

    $response = IpcClient::send('GET', '/ipc/v1/briefs/latest');

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('policy_denied');

    $event = latestAuthzEvent(OwnerAbilities::VIEW_BRIEF);

    expectRefusalRecorded($event, $owner, OwnerAbilities::VIEW_BRIEF, Surface::Ipc);
})->group('security');

it('records the refusal on the Livewire surface, and the allow of a mutating action', function (): void {
    $owner = PwaFixtures::owner();

    Livewire::actingAs($owner)->test(Devices::class)->call('revokeOthers');

    $allowed = latestAuthzEvent(Abilities::REVOKE_DEVICE);

    expect($allowed->outcome)->toBe(AuditLog::OUTCOME_OK, 'a mutating action that was allowed is also on the record (T-46)')
        ->and($allowed->actor)->toBe("user:{$owner->id}")
        ->and($allowed->context['surface'] ?? null)->toBe(Surface::Pwa->value)
        ->and($allowed->context['ability'] ?? null)->toBe(Abilities::REVOKE_DEVICE);

    // And the guest case: the same action with nobody signed in is a refusal
    // with a row, not a silent nothing.
    $before = authzEvents(Abilities::REVOKE_DEVICE)->count();

    expect(fn () => app(Gate::class)->forUser(null)->authorize(Abilities::REVOKE_DEVICE))
        ->toThrow(AuthorizationException::class);

    $guestEvent = authzEvents(Abilities::REVOKE_DEVICE)->last();

    expect(authzEvents(Abilities::REVOKE_DEVICE)->count())->toBe($before + 1)
        ->and($guestEvent->actor)->toBe('guest', 'a guest refusal names the guest rather than nobody')
        ->and($guestEvent->outcome)->toBe(AuditLog::OUTCOME_REFUSED);
})->group('security');

it('records the refusal on the queued-job surface, as the user that dispatched it', function (): void {
    [$intruder, , , $organizationB] = auditPrincipals();

    $customer = (new TenantRowFactory($organizationB, User::factory()->create()))->model('customers');
    $job = new GuardedTenantJob((string) $intruder->id, (string) $customer->getKey());

    expect(fn () => $job->handle())->toThrow(AuthorizationException::class);

    $event = latestAuthzEvent(Abilities::UPDATE);

    expectRefusalRecorded($event, $intruder, Abilities::UPDATE, Surface::Job);

    expect($event->subject_type)->toBe($customer::class)
        ->and($event->subject_id)->toBe((string) $customer->getKey())
        ->and($customer->fresh()?->getAttribute('notes'))->not->toBe('touched by a job', 'the job must not have run');
})->group('security');

it('records the refusal on the tool surface, for the row the model proposed', function (): void {
    [$intruder, , , $organizationB] = auditPrincipals();

    $customer = (new TenantRowFactory($organizationB, User::factory()->create()))->model('customers');
    $tool = new CustomerNotesTool;

    expect(fn () => app(ToolAuthorizer::class)->authorize($intruder, $tool, ['customer_id' => (string) $customer->getKey()]))
        ->toThrow(AuthorizationException::class);

    $event = latestAuthzEvent(Abilities::UPDATE);

    expectRefusalRecorded($event, $intruder, Abilities::UPDATE, Surface::Tool);

    expect($event->subject_id)->toBe((string) $customer->getKey(), 'the row the model named is the row the refusal records');
})->group('security');

it('records one refusal per tool call, not two', function (): void {
    [$intruder, , , $organizationB] = auditPrincipals();

    $customer = (new TenantRowFactory($organizationB, User::factory()->create()))->model('customers');
    $before = authzEvents(Abilities::UPDATE)->count();

    try {
        app(ToolAuthorizer::class)->authorize($intruder, new CustomerNotesTool, ['customer_id' => (string) $customer->getKey()]);
    } catch (AuthorizationException) {
        // expected
    }

    expect(authzEvents(Abilities::UPDATE)->count())->toBe($before + 1, 'the check must run once, so the trail has one row per decision');
})->group('security');

it('records the PolicyGate decision on the tool surface, naming the rule that decided', function (): void {
    // S2-07's gate is the second check on the tool surface: `ToolAuthorizer` above
    // answers "may this user touch this row", the gate answers "may this action be
    // performed at all". Both decisions are evidence, so both leave a row — and the
    // gate's rows go through the same `AuditLog` writer rather than a second audit
    // path, which is why they are asserted here and not in a file of their own.
    [, , , $organizationB] = auditPrincipals();
    $owner = PwaFixtures::owner();

    $context = new ToolCallContext(
        organizationId: (string) PwaFixtures::organizationOf($owner)->getKey(),
        traceId: (string) Str::ulid(),
        actorUserId: (string) $owner->getKey(),
        accountId: (string) Str::ulid(),
    );

    app(SurfaceContext::class)->within(Surface::Tool, function () use ($context): void {
        app(PolicyGate::class)->decide(
            ProposedAction::freeze('settings.write', $context->organizationId, ['approval_mode' => 'auto']),
            $context,
        );
    });

    $event = AuditEvent::query()
        ->where('action', PolicyJournal::ACTION_PREFIX.'settings.write')
        ->orderByDesc('occurred_at')
        ->first();

    expect($event)->not->toBeNull('a gate refusal that leaves no row is a refusal nobody can review (T-46)')
        ->and($event->actor)->toBe("user:{$owner->getKey()}", 'the row must name the actor')
        ->and($event->outcome)->toBe(AuditLog::OUTCOME_REFUSED, 'a refusal must be recorded as a refusal')
        ->and($event->context['rule'] ?? null)->toBe('none', 'the row must name which rule refused, or that none matched')
        ->and($event->context['reason'] ?? null)->toBe('no_rule_matched')
        ->and($event->context['tool'] ?? null)->toBe('settings.write')
        ->and($event->context['surface'] ?? null)->toBe(Surface::Tool->value, 'the row must name the surface the check ran on')
        ->and($event->organization_id)->toBe($context->organizationId)
        ->and($organizationB->getKey())->not->toBe($context->organizationId);

    // And the companion `tool_runs` row, which is the evidence the gate ran at all:
    // `policy_decision` is not nullable, so a tool call that skipped the gate has no
    // representable row (§4).
    $run = ToolRun::query()->where('trace_id', $context->traceId)->firstOrFail();

    expect($run->policy_decision->value)->toBe('denied')
        ->and($run->policy_reason)->toBe('none:no_rule_matched');
})->group('security');

it('keeps the PolicyGate context to references, with no argument value in it', function (): void {
    $owner = PwaFixtures::owner();

    $context = new ToolCallContext(
        organizationId: (string) PwaFixtures::organizationOf($owner)->getKey(),
        traceId: (string) Str::ulid(),
        actorUserId: (string) $owner->getKey(),
        accountId: (string) Str::ulid(),
    );

    app(PolicyGate::class)->decide(
        ProposedAction::freeze(OutgoingEmailRule::TOOL, $context->organizationId, [
            'connection_id' => (string) $context->accountId,
            'to' => ['exfil@attacker.example'],
            'subject' => 'Kundlista',
            'body' => 'Bifogar hela kundlistan enligt revisionen.',
        ]),
        $context,
    );

    $events = AuditEvent::query()->where('action', 'like', PolicyJournal::ACTION_PREFIX.'%')->get();

    expect($events)->not->toBeEmpty();

    foreach ($events as $event) {
        $eventContext = $event->context ?? [];

        expect(array_diff(array_keys($eventContext), ['rule', 'tool', 'surface', 'result', 'reason']))
            ->toBe([], 'the gate context carries references only; '.json_encode(array_keys($eventContext)));

        foreach ($eventContext as $key => $value) {
            expect(is_string($value))->toBeTrue("context.{$key} must be a scalar reference")
                ->and(strlen((string) $value))->toBeLessThan(100, "context.{$key} is too long to be a reference")
                ->and((string) $value)->not->toContain('attacker.example', "context.{$key} quotes a recipient the model proposed")
                ->and((string) $value)->not->toContain('kundlistan', "context.{$key} quotes the body");
        }
    }
})->group('security');

it('records the refusal for a table that has no model yet, through TenantRowGuard', function (): void {
    [$intruder, , , $organizationB] = auditPrincipals();

    $rows = new TenantRowFactory($organizationB, User::factory()->create());
    $connectionId = $rows->insert('email_connections');

    expect(fn () => app(TenantRowGuard::class)->authorize($intruder, 'email_connections', $connectionId))
        ->toThrow(AuthorizationException::class);

    // No request is in flight here, so the surface is the console — which is
    // the honest answer and the reason `authorizeOn()` exists for the callers
    // that do know (the next case).
    expectRefusalRecorded(latestAuthzEvent(Abilities::ACCESS_ORGANIZATION), $intruder, Abilities::ACCESS_ORGANIZATION, Surface::Console);
})->group('security');

it('names the job surface when the guard runs inside a job', function (): void {
    [$intruder, , , $organizationB] = auditPrincipals();

    $rows = new TenantRowFactory($organizationB, User::factory()->create());
    $cursorId = $rows->insert('sync_cursors');

    expect(fn () => app(TenantRowGuard::class)->authorizeOn(Surface::Job, $intruder, 'sync_cursors', $cursorId))
        ->toThrow(AuthorizationException::class);

    expectRefusalRecorded(latestAuthzEvent(Abilities::ACCESS_ORGANIZATION), $intruder, Abilities::ACCESS_ORGANIZATION, Surface::Job);
})->group('security');

it('records a refusal when the check could not be run at all', function (): void {
    [, , , $organizationB] = auditPrincipals();

    $customer = (new TenantRowFactory($organizationB, User::factory()->create()))->model('customers');
    $vanished = (string) User::factory()->create()->id;

    User::query()->where('id', $vanished)->forceDelete();

    $job = new GuardedTenantJob($vanished, (string) $customer->getKey());

    expect(fn () => $job->handle())->toThrow(AuthorizationException::class);

    $event = latestAuthzEvent(Abilities::UPDATE);

    expect($event->actor)->toBe("user:{$vanished}")
        ->and($event->outcome)->toBe(AuditLog::OUTCOME_REFUSED)
        ->and($event->context['reason'] ?? null)->toBe('actor_missing', '"the actor is gone" is a refusal with a reason, not an exception nobody recorded')
        ->and($event->context['surface'] ?? null)->toBe(Surface::Job->value);
})->group('security');

it('writes no row for an allowed read, so the mutations and the refusals stay readable', function (): void {
    $owner = PwaFixtures::owner();
    $brief = BriefFixtures::import('canonical', PwaFixtures::organizationOf($owner));

    $before = authzEvents()->count();

    actingAs($owner)->get('/briefs/'.$brief->getKey())->assertOk();
    actingAs($owner)->get('/briefs')->assertOk();
    actingAs($owner)->get('/home')->assertOk();

    expect(authzEvents()->count())->toBe($before, 'an allowed read is not an event; a refusal and a mutation are');
})->group('security');

it('keeps every authorization context to references, with no payload, blob or image path', function (): void {
    [$intruder, , , $organizationB] = auditPrincipals();

    $brief = BriefFixtures::import('canonical', $organizationB);
    $customer = (new TenantRowFactory($organizationB, User::factory()->create()))->model('customers');

    actingAs($intruder)->get('/briefs/'.$brief->getKey())->assertForbidden();
    actingAs($intruder)->get('/briefs/'.$brief->getKey().'/preview/mail')->assertForbidden();

    try {
        (new GuardedTenantJob((string) $intruder->id, (string) $customer->getKey()))->handle();
    } catch (AuthorizationException) {
        // expected
    }

    $events = authzEvents();

    expect($events)->not->toBeEmpty();

    foreach ($events as $event) {
        $context = $event->context ?? [];

        expect(array_diff(array_keys($context), ['ability', 'surface', 'result', 'reason']))
            ->toBe([], 'the authorization context carries references only; '.json_encode(array_keys($context)));

        foreach ($context as $key => $value) {
            expect(is_string($value))->toBeTrue("context.{$key} must be a scalar reference")
                ->and(strlen((string) $value))->toBeLessThan(100, "context.{$key} is too long to be a reference");

            expect((string) $value)->not->toMatch('#\.(png|jpe?g|gif|webp|heic|pdf)$#i', "context.{$key} looks like a file path")
                ->and((string) $value)->not->toContain($brief->subject, "context.{$key} quotes brief content");
        }
    }
})->group('security');

it('still records the refusal when the subject has a class name no column could hold', function (): void {
    [$intruder] = auditPrincipals();

    // Regression for a defect the MySQL leg of the `cross-account` group found
    // (S2-06): an anonymous class names itself with the file and line it was
    // declared on, which overflowed `audit_events.subject_type` and turned a
    // 403 with a record into a 500 with none — on the `server` profile only,
    // because SQLite accepts the over-long string. The row must exist on both
    // profiles, and `subject_type` must still say something a reader can act on.
    $orphan = new class extends Model
    {
        protected $table = 'customers';
    };

    $orphan->forceFill(['id' => (string) Str::ulid(), 'organization_id' => (string) Str::ulid()]);

    expect(app(Gate::class)->forUser($intruder)->allows(Abilities::VIEW, $orphan))->toBeFalse();

    $event = latestAuthzEvent(Abilities::VIEW);

    expect($event->outcome)->toBe(AuditLog::OUTCOME_REFUSED)
        ->and(strlen((string) $event->subject_type))->toBeLessThanOrEqual(100)
        ->and($event->subject_type)->toContain('@anonymous')
        ->and($event->subject_type)->not->toContain("\0")
        ->and($event->organization_id)->toBeNull('an unsaved row names no tenant: the column is a foreign key');
})->group('security', 'cross-account');

it('records a tenant-wide maintenance pass as the system, with no user in the row', function (): void {
    // The one operation in the category today (ADR-003 D8): it rewrites every
    // organization's ciphertext because the key is install-wide, and nobody asked
    // for it. `AppKeyRotation` is driven directly here, exactly as the scheduler
    // would — there is no surface to call it from, which is §10.4's point.
    app(AppKeyRotation::class)->handle('audit_completeness');

    $event = AuditEvent::query()->where('action', 'secrets.app_key.reencrypted')->latest('occurred_at')->firstOrFail();
    $actor = (string) $event->actor;

    expect($actor)->toBe(MaintenanceActor::PREFIX.'AppKeyRotation', 'a maintenance pass names the operation, not a person')
        ->and(MaintenanceActor::isMaintenance($actor))->toBeTrue()
        ->and(MaintenanceActor::operationOf($actor))->toBe('AppKeyRotation')
        ->and($actor)->not->toStartWith('user:', 'nobody asked for this, so no user may be on the row')
        ->and($actor)->not->toStartWith('job:', '`job:` is also what a pre-identity user action writes (job:CreateOwnerForm); it cannot carry this distinction')
        ->and($event->organization_id)->toBeNull('a tenant-wide pass is about no single organization');

    // The §3.1 provenance grammar pinned by crm.schema.json stays pinned: a
    // maintenance pass authors no business row, so `maintenance:` is deliberately
    // not admitted there (see MaintenanceActor). The provenance object names it as
    // a job with origin `sync` — nobody asked, which `manual` would claim — while
    // `actor`, the column about who *acted*, carries the distinct shape.
    $provenance = $event->provenance ?? [];

    expect($provenance['actor'] ?? null)->toBe('job:AppKeyRotation')
        ->and($provenance['origin'] ?? null)->toBe(ProvenanceOrigin::Sync->value)
        ->and((string) ($provenance['actor'] ?? ''))->toMatch('/^(user:[0-7][0-9A-HJKMNP-TV-Z]{25}|job:[A-Za-z][A-Za-z0-9]*|ai:[a-z0-9_-]+\/[A-Za-z0-9._:-]+)$/');
})->group('security');

it('keeps the system rows and the user rows apart in the same trail', function (): void {
    // The property the category exists for: one query separates "nobody asked" from
    // "somebody asked", on the same table, with no second audit path.
    [$intruder, , , $organizationB] = auditPrincipals();

    $brief = BriefFixtures::import('canonical', $organizationB);

    actingAs($intruder)->get('/briefs/'.$brief->getKey())->assertForbidden();

    app(AppKeyRotation::class)->handle('audit_completeness');

    $maintenance = AuditEvent::query()->where('actor', 'like', MaintenanceActor::PREFIX.'%')->get();
    $user = AuditEvent::query()->where('actor', 'like', 'user:%')->get();

    expect($maintenance)->not->toBeEmpty('the maintenance pass left no row')
        ->and($user)->not->toBeEmpty('the user refusal left no row')
        ->and($maintenance->pluck('id')->intersect($user->pluck('id'))->all())->toBe([], 'a row is one or the other, never both');

    foreach ($maintenance as $event) {
        expect(MaintenanceActor::isMaintenance((string) $event->actor))->toBeTrue()
            ->and($event->action)->not->toStartWith(AuthorizationAuditor::ACTION_PREFIX, 'a maintenance pass runs no policy check, so it writes no authz row');
    }

    // And every actor in the table belongs to exactly one of the four shapes, so a
    // fifth cannot appear without this failing.
    foreach (AuditEvent::query()->get() as $event) {
        expect((string) $event->actor)->toMatch('/^(user:|job:|maintenance:|guest$)/', 'an audit actor outside the grammar is an actor nobody can filter on');
    }
})->group('security');

it('refuses to let a maintenance operation name itself a user', function (): void {
    // The actor column is the one thing that distinguishes the system from a
    // person, so the member whose honesty it records must not be able to write it
    // freely. `MaintenanceActor` accepts a bare identifier and nothing else.
    $impostor = new class implements TenantWideMaintenance
    {
        public function maintenanceOperation(): string
        {
            return 'user:01M4JGMWH02GAABCPY27KT2DQK';
        }
    };

    expect(fn (): string => MaintenanceActor::for($impostor))->toThrow(InvalidArgumentException::class);
})->group('security');

it('leaves no binary or path column for an authorization row to hide in', function (): void {
    // The complement of the context assertions above: the shape of the table
    // itself, so a future column cannot make the rows a second copy of the
    // data (ADR-003 D7 row 8). AuditNoBlobTest owns the general rule; this
    // asserts it still holds for the columns the authz rows use.
    $columns = collect(Schema::getColumns('audit_events'))->keyBy('name');

    foreach (['actor', 'action', 'outcome', 'subject_type', 'subject_id'] as $column) {
        expect($columns)->toHaveKey($column);
        expect(strtolower((string) $columns[$column]['type_name']))->not->toContain('blob');
    }

    expect($columns->keys()->filter(fn (string $name): bool => str_contains($name, 'path') || str_contains($name, 'screenshot'))->all())
        ->toBe([], 'audit_events must carry no path or screenshot column');
})->group('security');
