<?php

declare(strict_types=1);

use App\Models\AuditEvent;
use App\Models\InstallStateTransition;
use App\Models\Membership;
use App\Models\MembershipRole;
use App\Models\Organization;
use App\Models\User;
use App\Support\Install\InstallState;
use App\Support\Install\InstallStateStore;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Ipc\IpcClient;
use Tests\Support\OwnerAccount;

/*
 * IPC contract §6.9 / §2.1 (threat model T-72, issue #121): the installer's
 * CREATE_OWNER step over the paired `menubar` session — the one operation
 * that runs before an owner exists and therefore the one without an
 * owner-bound gate. What authorises it is the `menubar` token plus the
 * absence of an owner; the moment one exists the route refuses, every time,
 * with `409` / `owner_exists`, which is what lets a data-preserving
 * reinstall reach the next screen instead of a red box.
 */

uses(DatabaseTransactions::class);

function ownerBody(array $overrides = []): array
{
    return [...[
        'name' => 'Ada Owner',
        'email' => 'Ada.Owner@Example.test',
        'timezone' => 'Europe/Stockholm',
        'language' => 'sv',
    ], ...$overrides];
}

beforeEach(function (): void {
    OwnerAccount::clear();
    InstallStateTransition::query()->delete();
});

it('creates the owner, its organization and the owner membership on a fresh install', function (): void {
    $nonce = IpcClient::nonce();
    $response = IpcClient::send('POST', '/ipc/v1/owner', ownerBody(), ['nonce' => $nonce]);

    $response->assertOk();

    $owner = User::query()->where('email', 'ada.owner@example.test')->firstOrFail();
    $membership = Membership::query()->where('role', MembershipRole::Owner->value)->firstOrFail();
    $organization = Organization::query()->findOrFail($membership->organization_id);

    expect($response->json('data'))->toBe(['created' => true])
        ->and(IpcClient::responseSignatureIsValid($response, $nonce))->toBeTrue()
        ->and($membership->user_id)->toBe($owner->id)
        ->and($owner->getAttribute('email_verified_at'))->toBeNull()
        ->and($organization->timezone)->toBe('Europe/Stockholm')
        ->and($organization->language)->toBe('sv')
        // Screen 4 asks for no workspace name, so the workspace is the owner's.
        ->and($organization->name)->toBe('Ada Owner')
        ->and(app(InstallStateStore::class)->current())->toBe(InstallState::Installing);
});

it('records the creation as an audit event that names the IPC door', function (): void {
    IpcClient::send('POST', '/ipc/v1/owner', ownerBody(), ['request_id' => '01M4G0PT40SQ5H1AFST1HB6P40']);

    $owner = User::query()->where('email', 'ada.owner@example.test')->firstOrFail();
    $event = AuditEvent::query()->where('action', 'auth.owner_created')->where('subject_id', $owner->id)->firstOrFail();

    expect($event->actor)->toBe('job:IpcCreateOwner')
        ->and($event->outcome)->toBe('ok')
        ->and($event->context['via'])->toBe('ipc')
        ->and($event->context['client'])->toBe('menubar')
        ->and($event->context['session_id'])->toBe(IpcClient::SESSION)
        ->and($event->context['request_id'])->toBe('01M4G0PT40SQ5H1AFST1HB6P40');
});

it('takes the workspace name, the zone and the language from the body when they are given', function (): void {
    IpcClient::send('POST', '/ipc/v1/owner', ownerBody(['organization' => 'Example Organization', 'timezone' => 'Europe/Rome', 'language' => 'it']));

    $organization = Organization::query()->firstOrFail();

    expect($organization->name)->toBe('Example Organization')
        ->and($organization->slug)->toBe('example-organization')
        ->and($organization->timezone)->toBe('Europe/Rome')
        ->and($organization->language)->toBe('it');
});

it('answers one fact and no credential, identifier or submitted address', function (): void {
    $response = IpcClient::send('POST', '/ipc/v1/owner', ownerBody());
    $body = (string) $response->getContent();

    $owner = User::query()->where('email', 'ada.owner@example.test')->firstOrFail();
    $membership = Membership::query()->where('role', MembershipRole::Owner->value)->firstOrFail();

    expect(array_keys((array) $response->json('data')))->toBe(['created'])
        ->and($body)->not->toContain($owner->id)
        ->and($body)->not->toContain($membership->id)
        ->and($body)->not->toContain($membership->organization_id)
        ->and($body)->not->toContain('ada.owner@example.test')
        ->and($body)->not->toContain('Ada Owner')
        ->and($body)->not->toContain('lxai1')
        ->and($body)->not->toContain(IpcClient::token())
        ->and($response->headers->get('Set-Cookie'))->toBeNull();
});

it('refuses a repeated install with owner_exists, creates nothing and audits the refusal', function (): void {
    $first = OwnerAccount::create();
    $usersBefore = User::query()->count();
    $organizationsBefore = Organization::query()->count();

    $nonce = IpcClient::nonce();
    $response = IpcClient::send('POST', '/ipc/v1/owner', ownerBody(['email' => 'second@example.test', 'name' => 'Second Person']), ['nonce' => $nonce]);

    expect($response->getStatusCode())->toBe(409)
        ->and($response->json('error.code'))->toBe('owner_exists')
        ->and($response->json('error.message'))->toBe('An owner account already exists on this Mac.')
        ->and($response->json('error.details'))->toBeNull()
        ->and($response->json('error.retry_after_seconds'))->toBeNull()
        // The refusal is signed: the client must be able to trust it (§4.5).
        ->and(IpcClient::responseSignatureIsValid($response, $nonce))->toBeTrue()
        ->and((string) $response->getContent())->not->toContain($first->id)
        ->and(Membership::query()->where('role', MembershipRole::Owner->value)->count())->toBe(1)
        ->and(Membership::query()->where('role', MembershipRole::Owner->value)->value('user_id'))->toBe($first->id)
        ->and(User::query()->count())->toBe($usersBefore)
        ->and(Organization::query()->count())->toBe($organizationsBefore)
        ->and(User::query()->where('email', 'second@example.test')->exists())->toBeFalse();

    $refusal = AuditEvent::query()->where('action', 'auth.owner_create_refused')->firstOrFail();

    expect($refusal->outcome)->toBe('refused')
        ->and($refusal->actor)->toBe("user:{$first->id}")
        ->and($refusal->context['reason'])->toBe('owner_exists')
        ->and($refusal->context['via'])->toBe('ipc')
        ->and($refusal->context['client'])->toBe('menubar');
});

it('refuses every further attempt, not only the second', function (): void {
    OwnerAccount::create();

    foreach (range(1, 3) as $attempt) {
        expect(IpcClient::send('POST', '/ipc/v1/owner', ownerBody())->json('error.code'))->toBe('owner_exists', "attempt {$attempt}");
    }

    expect(Membership::query()->where('role', MembershipRole::Owner->value)->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'auth.owner_create_refused')->count())->toBe(3);
});

it('creates exactly one owner when the install asks twice with different details', function (): void {
    $first = IpcClient::send('POST', '/ipc/v1/owner', ownerBody());
    $second = IpcClient::send('POST', '/ipc/v1/owner', ownerBody(['name' => 'Second Person', 'email' => 'second@example.test']));

    expect($first->getStatusCode())->toBe(200)
        ->and($second->getStatusCode())->toBe(409)
        ->and($second->json('error.code'))->toBe('owner_exists')
        ->and(Membership::query()->where('role', MembershipRole::Owner->value)->count())->toBe(1)
        ->and(User::query()->count())->toBe(1)
        ->and(Organization::query()->count())->toBe(1)
        ->and(User::query()->value('email'))->toBe('ada.owner@example.test');
});

it('waits on the lea:create-owner lock instead of creating an owner beside a concurrent run', function (): void {
    // The one proof that the IPC path does not bypass CreateOwner's lock:
    // while another holder owns it, the request blocks for the documented 5 s
    // and then fails closed. Nothing is created.
    $lock = Cache::lock('lea:create-owner', 10);

    expect($lock->get())->toBeTrue();

    try {
        $response = IpcClient::send('POST', '/ipc/v1/owner', ownerBody());
    } finally {
        $lock->release();
    }

    expect($response->getStatusCode())->toBe(500)
        ->and($response->json('error.code'))->toBe('internal')
        ->and(Membership::query()->where('role', MembershipRole::Owner->value)->count())->toBe(0)
        ->and(User::query()->count())->toBe(0)
        ->and(Organization::query()->count())->toBe(0);
});

it('refuses a supervisor token with forbidden_scope and an absent token with session_invalid', function (): void {
    $supervisor = IpcClient::send('POST', '/ipc/v1/owner', ownerBody(), ['client' => 'supervisor']);

    expect($supervisor->getStatusCode())->toBe(403)
        ->and($supervisor->json('error.code'))->toBe('forbidden_scope');

    $anonymous = IpcClient::send('POST', '/ipc/v1/owner', ownerBody(), ['token' => null]);

    expect($anonymous->getStatusCode())->toBe(401)
        ->and($anonymous->json('error.code'))->toBe('session_invalid')
        // §4.5: emitted before a signing key exists, therefore unsigned.
        ->and($anonymous->headers->has('X-Lxai-Signature'))->toBeFalse();

    $wrongMac = IpcClient::send('POST', '/ipc/v1/owner', ownerBody(), ['token' => 'lxai1.menubar.'.IpcClient::SESSION.'.1794240000.'.str_repeat('a', 32)]);

    expect($wrongMac->getStatusCode())->toBe(401)
        ->and($wrongMac->json('error.code'))->toBe('session_invalid');

    expect(User::query()->count())->toBe(0)
        ->and(Membership::query()->where('role', MembershipRole::Owner->value)->count())->toBe(0);
});

it('rejects a body that asserts an identity, an unknown field or invalid details', function (array $body, string $field): void {
    $response = IpcClient::send('POST', '/ipc/v1/owner', $body);

    expect($response->getStatusCode())->toBe(422)
        ->and($response->json('error.code'))->toBe('validation_failed')
        ->and(array_keys((array) $response->json('error.details')))->toContain($field)
        ->and(User::query()->count())->toBe(0);
})->with([
    'a user id' => [['name' => 'Ada', 'email' => 'ada@example.test', 'user_id' => '01M4G0QK2T6Z5P9Y3R7W1A8C4E'], 'user_id'],
    'a role' => [['name' => 'Ada', 'email' => 'ada@example.test', 'role' => 'owner'], 'role'],
    'an organization id' => [['name' => 'Ada', 'email' => 'ada@example.test', 'organization_id' => '01M4G0QK2T6Z5P9Y3R7W1A8C4E'], 'organization_id'],
    'a password' => [['name' => 'Ada', 'email' => 'ada@example.test', 'password' => 'hunter2'], 'password'],
    'no name' => [['email' => 'ada@example.test'], 'name'],
    'a blank name' => [['name' => '   ', 'email' => 'ada@example.test'], 'name'],
    'no address' => [['name' => 'Ada'], 'email'],
    'a malformed address' => [['name' => 'Ada', 'email' => 'not-an-address'], 'email'],
    'an unknown zone' => [['name' => 'Ada', 'email' => 'ada@example.test', 'timezone' => 'Mars/Olympus'], 'timezone'],
    'a malformed language tag' => [['name' => 'Ada', 'email' => 'ada@example.test', 'language' => '12'], 'language'],
]);

it('keeps the owner budget to five attempts a minute', function (): void {
    foreach (range(1, 5) as $attempt) {
        expect(IpcClient::send('POST', '/ipc/v1/owner', ownerBody(['email' => "ada{$attempt}@example.test"]))->getStatusCode())
            ->toBeIn([200, 409], "attempt {$attempt}");
    }

    $limited = IpcClient::send('POST', '/ipc/v1/owner', ownerBody());

    expect($limited->getStatusCode())->toBe(429)
        ->and($limited->json('error.code'))->toBe('rate_limited')
        ->and($limited->headers->get('Retry-After'))->not->toBeNull();
});
