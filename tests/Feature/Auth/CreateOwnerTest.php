<?php

declare(strict_types=1);

use App\Console\Commands\OwnerCreateCommand;
use App\Livewire\Install\CreateOwnerForm;
use App\Models\AuditEvent;
use App\Models\InstallStateTransition;
use App\Models\Membership;
use App\Models\MembershipRole;
use App\Models\Organization;
use App\Models\User;
use App\Security\Authorization\CreateOwner;
use App\Security\Authorization\OwnerAlreadyExistsException;
use App\Security\Authorization\OwnerDetails;
use App\Support\Install\InstallState;
use App\Support\Install\InstallStateStore;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\OwnerAccount;

use function Pest\Laravel\artisan;
use function Pest\Laravel\assertAuthenticatedAs;
use function Pest\Laravel\get;

/*
 * S1-02 criteria 1 and 4 (§12.1 CREATE_OWNER, threat model T-72): one owner
 * through the installer path with no Terminal for the user, never a second
 * one from a repeated install.
 */

uses(DatabaseTransactions::class);

beforeEach(function (): void {
    OwnerAccount::clear();
    InstallStateTransition::query()->delete();
});

it('creates organization, user and owner membership in one step and marks the install as installing', function (): void {
    $created = app(CreateOwner::class)->create(new OwnerDetails('Ada Owner', 'Ada.Owner@Example.test', 'Example Organization', 'Europe/Stockholm', 'sv'), 'job:test');

    expect($created->user->email)->toBe('ada.owner@example.test')
        ->and($created->user->getAttribute('email_verified_at'))->toBeNull()
        ->and($created->organization->slug)->toBe('example-organization')
        ->and($created->organization->timezone)->toBe('Europe/Stockholm')
        ->and($created->organization->language)->toBe('sv')
        ->and($created->membership->role)->toBe(MembershipRole::Owner)
        ->and($created->membership->organization_id)->toBe($created->organization->id)
        ->and($created->installState)->toBe(InstallState::Installing)
        ->and(app(InstallStateStore::class)->current())->toBe(InstallState::Installing)
        ->and(AuditEvent::query()->where('action', 'auth.owner_created')->where('subject_id', $created->user->id)->exists())->toBeTrue();
});

it('does not move an install marker that is already past not_installed', function (): void {
    app(InstallStateStore::class)->transition(InstallState::CoreReady, 'test');

    $created = app(CreateOwner::class)->create(new OwnerDetails('Ada Owner', 'owner@example.test', 'Example Organization'), 'job:test');

    expect($created->installState)->toBe(InstallState::CoreReady)
        ->and(app(InstallStateStore::class)->current())->toBe(InstallState::CoreReady);
});

it('refuses to create a second owner and leaves exactly one owner behind', function (): void {
    $first = OwnerAccount::create();
    $usersBefore = User::query()->count();
    $organizationsBefore = Organization::query()->count();

    expect(fn () => OwnerAccount::create('second@example.test', 'Second Person'))
        ->toThrow(OwnerAlreadyExistsException::class);

    expect(Membership::query()->where('role', MembershipRole::Owner->value)->count())->toBe(1)
        ->and(Membership::query()->where('role', MembershipRole::Owner->value)->value('user_id'))->toBe($first->id)
        ->and(User::query()->count())->toBe($usersBefore)
        ->and(Organization::query()->count())->toBe($organizationsBefore)
        ->and(AuditEvent::query()->where('action', 'auth.owner_create_refused')->exists())->toBeTrue();
});

it('validates the owner details before touching the database', function (): void {
    expect(fn () => OwnerDetails::fromInput(['name' => '', 'email' => 'not-an-address', 'organization' => 'X', 'timezone' => 'Mars/Olympus', 'language' => '12']))
        ->toThrow(ValidationException::class);

    expect(User::query()->count())->toBe(0);
});

it('creates the owner through lea:owner:create with a JSON result for the installer', function (): void {
    $exitCode = Artisan::call('lea:owner:create', [
        '--name' => 'Ada Owner',
        '--email' => 'owner@example.test',
        '--organization' => 'Example Organization',
        '--timezone' => 'Europe/Stockholm',
        '--language' => 'en',
        '--json' => true,
    ]);
    $result = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
    $owner = User::query()->where('email', 'owner@example.test')->firstOrFail();

    expect($exitCode)->toBe(0)
        ->and(array_keys($result))->toBe(['status', 'user_id', 'organization_id', 'membership_id', 'email', 'install_state'])
        ->and($result['status'])->toBe('created')
        ->and($result['user_id'])->toBe($owner->id)
        ->and($result['install_state'])->toBe('installing');
});

it('exits with the distinct owner-exists code on a repeated install and prints why', function (): void {
    OwnerAccount::create();

    artisan('lea:owner:create', [
        '--name' => 'Second Person',
        '--email' => 'second@example.test',
        '--organization' => 'Another Organization',
    ])
        ->expectsOutputToContain('An owner account already exists')
        ->assertExitCode(OwnerCreateCommand::OWNER_EXISTS);

    artisan('lea:owner:create', [
        '--name' => 'Second Person',
        '--email' => 'second@example.test',
        '--organization' => 'Another Organization',
        '--json' => true,
    ])
        ->expectsOutputToContain('"status":"owner_exists"')
        ->assertExitCode(3);

    expect(Membership::query()->where('role', MembershipRole::Owner->value)->count())->toBe(1)
        ->and(User::query()->where('email', 'second@example.test')->exists())->toBeFalse();
});

it('exits with the invalid code when the details do not validate', function (): void {
    artisan('lea:owner:create', ['--name' => 'Ada', '--email' => 'nope', '--organization' => 'Example', '--json' => true])
        ->expectsOutputToContain('"status":"invalid"')
        ->assertExitCode(OwnerCreateCommand::INVALID);
});

it('serves the in-app create-owner screen only while no owner exists', function (): void {
    get('/install/owner')->assertOk()->assertSee('Create the owner account')->assertDontSee('type="password"', false);
    get('/login')->assertRedirect(route('install.owner'));

    OwnerAccount::create();

    get('/install/owner')->assertNotFound();
});

it('creates the owner from the in-app screen and signs the installer in', function (): void {
    Livewire::test(CreateOwnerForm::class)
        ->set('name', 'Ada Owner')
        ->set('email', 'owner@example.test')
        ->set('organization', 'Example Organization')
        ->set('timezone', 'Europe/Stockholm')
        ->set('language', 'sv')
        ->call('create')
        ->assertHasNoErrors()
        ->assertRedirect(route('home'));

    $owner = User::query()->where('email', 'owner@example.test')->firstOrFail();

    assertAuthenticatedAs($owner);

    expect(Membership::query()->where('user_id', $owner->id)->where('role', MembershipRole::Owner->value)->exists())->toBeTrue()
        ->and(AuditEvent::query()->where('action', 'auth.logged_in')->where('subject_id', $owner->id)->exists())->toBeTrue();
});
