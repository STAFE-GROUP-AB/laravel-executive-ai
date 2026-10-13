<?php

declare(strict_types=1);

use App\Livewire\Auth\Reauthenticate;
use App\Models\AuditEvent;
use App\Models\User;
use App\Security\Authorization\Devices\LoginMethod;
use App\Security\Authorization\Reauthentication\CriticalAction;
use App\Security\Authorization\Reauthentication\ReauthenticationRequired;
use App\Security\Authorization\Reauthentication\Reauthenticator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Support\OwnerAccount;
use Tests\Support\PasskeyFixtures;

use function Pest\Laravel\travelTo;

/*
 * Threat model T-71: a critical action performed without re-authentication.
 * One case per path T-71 names — approving an outbound action, enabling
 * remote access, rotating a key — plus the property that makes the control
 * worth having: a **merely fresh session does not satisfy it**. The proof is
 * bound to the action, to a single-use nonce and to a time window, and it is
 * spent by the first `assert()` that accepts it.
 *
 * The PWA primitive lives here; the menu bar's equivalent is the Supervisor's
 * elevation (ipc-contract §7.3), which is a different mechanism for a surface
 * that has no PWA session, and approvals are never grantable over IPC at all.
 */

uses(DatabaseTransactions::class);

/** The owner account these tests act as; a function rather than a test property, which Larastan cannot see. */
function reauthOwner(): User
{
    return User::query()->where('email', OwnerAccount::EMAIL)->firstOrFail();
}

/** Sign the owner in the way a human would have, so "the session is fresh" is literally true. */
function signedInOwner(): User
{
    OwnerAccount::clear();
    $owner = OwnerAccount::create();

    app('session.store')->start();
    Auth::guard('web')->login($owner);
    session()->regenerate();

    return $owner;
}

beforeEach(function (): void {
    PasskeyFixtures::trustBothOrigins();
    signedInOwner();
    Mail::fake();
});

afterEach(function (): void {
    PasskeyFixtures::forgetTrustedHosts();
});

dataset('critical actions', [
    'approving an outbound action' => [CriticalAction::ApproveOutboundAction],
    'enabling private remote access' => [CriticalAction::EnableRemoteAccess],
    'rotating a key' => [CriticalAction::RotateKeys],
]);

it('refuses the action on a merely fresh session, and allows it only after a proof', function (CriticalAction $action): void {
    $reauth = app(Reauthenticator::class);

    // The session was established seconds ago and the owner is authenticated.
    expect(Auth::guard('web')->check())->toBeTrue();

    $challenge = $reauth->challenge($action);

    // Signed in, challenged, but nothing proven yet: the action is still refused.
    expect($reauth->passes($action, $challenge->nonce))->toBeFalse()
        ->and(fn () => $reauth->assert($action, $challenge->nonce))
        ->toThrow(ReauthenticationRequired::class, 'not_verified');

    $reauth->sendCode();

    expect($reauth->satisfyWithOtp($challenge->nonce, OwnerAccount::lastMailedCode())->succeeded())->toBeTrue();

    $proof = $reauth->assert($action, $challenge->nonce);

    expect($proof->action)->toBe($action)
        ->and($proof->nonce)->toBe($challenge->nonce)
        ->and($proof->method)->toBe(LoginMethod::Otp);
})->with('critical actions');

it('never lets a proof for one action stand in for another', function (CriticalAction $action): void {
    $reauth = app(Reauthenticator::class);
    $others = array_values(array_filter(CriticalAction::cases(), fn (CriticalAction $case): bool => $case !== $action));

    $challenge = $reauth->challenge($action);
    $reauth->sendCode();
    $reauth->satisfyWithOtp($challenge->nonce, OwnerAccount::lastMailedCode());

    foreach ($others as $other) {
        expect($reauth->passes($other, $challenge->nonce))->toBeFalse()
            ->and(fn () => $reauth->assert($other, $challenge->nonce))
            ->toThrow(ReauthenticationRequired::class, 'action_mismatch');
    }

    // The proof is untouched by the refusals and still works for its own action.
    expect($reauth->assert($action, $challenge->nonce)->action)->toBe($action);
})->with('critical actions');

it('spends the proof: the same nonce cannot authorise the action twice', function (CriticalAction $action): void {
    $reauth = app(Reauthenticator::class);

    $challenge = $reauth->challenge($action);
    $reauth->sendCode();
    $reauth->satisfyWithOtp($challenge->nonce, OwnerAccount::lastMailedCode());

    $reauth->assert($action, $challenge->nonce);

    expect($reauth->passes($action, $challenge->nonce))->toBeFalse()
        ->and(fn () => $reauth->assert($action, $challenge->nonce))
        ->toThrow(ReauthenticationRequired::class, 'consumed');
})->with('critical actions');

it('expires the proof after the configured window', function (CriticalAction $action): void {
    $reauth = app(Reauthenticator::class);

    $challenge = $reauth->challenge($action);
    $reauth->sendCode();
    $reauth->satisfyWithOtp($challenge->nonce, OwnerAccount::lastMailedCode());

    expect($reauth->passes($action, $challenge->nonce))->toBeTrue();

    travelTo(now()->addSeconds($reauth->windowSeconds() + 1));

    expect($reauth->passes($action, $challenge->nonce))->toBeFalse()
        ->and(fn () => $reauth->assert($action, $challenge->nonce))
        ->toThrow(ReauthenticationRequired::class, 'expired');
})->with('critical actions');

it('refuses a nonce nobody issued, however well formed', function (): void {
    $reauth = app(Reauthenticator::class);

    expect(fn () => $reauth->assert(CriticalAction::ApproveOutboundAction, 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA'))
        ->toThrow(ReauthenticationRequired::class, 'missing')
        ->and($reauth->passes(CriticalAction::ApproveOutboundAction, ''))->toBeFalse();
});

it('stops accepting an answer once the challenge itself has expired', function (): void {
    $reauth = app(Reauthenticator::class);
    $challenge = $reauth->challenge(CriticalAction::EnableRemoteAccess);
    $reauth->sendCode();
    $code = OwnerAccount::lastMailedCode();

    travelTo(now()->addSeconds($reauth->challengeTtlSeconds() + 1));

    expect($reauth->satisfyWithOtp($challenge->nonce, $code)->succeeded())->toBeFalse()
        ->and($reauth->passes(CriticalAction::EnableRemoteAccess, $challenge->nonce))->toBeFalse();
});

it('accepts a passkey as the proof and records which factor was used', function (): void {
    ['device' => $device] = PasskeyFixtures::register(reauthOwner(), PasskeyFixtures::appOrigin());
    $reauth = app(Reauthenticator::class);

    $challenge = $reauth->challenge(CriticalAction::RotateKeys);
    $options = $reauth->passkeyOptions();

    expect($reauth->satisfyWithPasskey($challenge->nonce, $device->assert($options, PasskeyFixtures::appOrigin(), reauthOwner()->id)))->toBeTrue();

    $proof = $reauth->assert(CriticalAction::RotateKeys, $challenge->nonce);

    expect($proof->method)->toBe(LoginMethod::Passkey)
        ->and(AuditEvent::query()->where('action', 'auth.reauth_verified')->get()->pluck('context')->pluck('method'))->toContain('passkey');
});

it('refuses a passkey belonging to someone else', function (): void {
    $stranger = User::factory()->create();
    ['device' => $strangerDevice] = PasskeyFixtures::register($stranger, PasskeyFixtures::appOrigin());

    $reauth = app(Reauthenticator::class);
    $challenge = $reauth->challenge(CriticalAction::ApproveOutboundAction);
    $options = $reauth->passkeyOptions();

    expect($reauth->satisfyWithPasskey($challenge->nonce, $strangerDevice->assert($options, PasskeyFixtures::appOrigin(), $stranger->id)))->toBeFalse()
        ->and($reauth->passes(CriticalAction::ApproveOutboundAction, $challenge->nonce))->toBeFalse();
});

it('writes an audit trail for every outcome and never the credential', function (): void {
    $reauth = app(Reauthenticator::class);
    $challenge = $reauth->challenge(CriticalAction::ApproveOutboundAction);
    $reauth->sendCode();
    $code = OwnerAccount::lastMailedCode();

    $reauth->satisfyWithOtp($challenge->nonce, $code === '000000' ? '111111' : '000000');
    $reauth->satisfyWithOtp($challenge->nonce, $code);
    $reauth->assert(CriticalAction::ApproveOutboundAction, $challenge->nonce);

    try {
        $reauth->assert(CriticalAction::ApproveOutboundAction, $challenge->nonce);
    } catch (ReauthenticationRequired) {
        // expected: the proof was spent above.
    }

    $actions = AuditEvent::query()->pluck('action');
    $serialised = AuditEvent::query()->get()->map(fn (AuditEvent $event): string => json_encode($event->getAttributes(), JSON_THROW_ON_ERROR))->implode("\n");

    expect($actions)->toContain('auth.reauth_challenged')
        ->toContain('auth.reauth_verified')
        ->toContain('auth.reauth_consumed')
        ->toContain('auth.reauth_refused')
        ->and($serialised)->not->toContain($code)
        ->and($serialised)->not->toContain($challenge->nonce);
});

it('drives the whole thing from the component a consumer embeds', function (): void {
    $reauth = app(Reauthenticator::class);
    $challenge = $reauth->challenge(CriticalAction::EnableRemoteAccess);

    $component = Livewire::actingAs(reauthOwner())
        ->test(Reauthenticate::class, ['action' => CriticalAction::EnableRemoteAccess->value, 'nonce' => $challenge->nonce])
        ->call('sendCode')
        ->assertSet('step', 'code');

    $component->set('code', OwnerAccount::lastMailedCode())
        ->call('verifyCode')
        ->assertHasNoErrors()
        ->assertSet('step', 'done')
        ->assertDispatched('reauth-satisfied');

    expect(app(Reauthenticator::class)->assert(CriticalAction::EnableRemoteAccess, $challenge->nonce)->method)->toBe(LoginMethod::Otp);
});

it('names every T-71 critical path, so a new one cannot be added without a case here', function (): void {
    expect(array_map(fn (CriticalAction $action): string => $action->value, CriticalAction::cases()))
        ->toBe(['approvals.approve', 'remote-access.enable', 'keys.rotate', 'privacy.mode_change']);
});
