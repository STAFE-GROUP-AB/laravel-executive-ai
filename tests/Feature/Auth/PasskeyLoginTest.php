<?php

declare(strict_types=1);

use App\Livewire\Auth\Login;
use App\Models\AuditEvent;
use App\Models\AuthDevice;
use App\Models\PasskeyCredential;
use App\Models\User;
use App\Security\Authorization\Devices\LoginMethod;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Support\OwnerAccount;
use Tests\Support\PasskeyFixtures;
use Tests\Support\VirtualAuthenticator;

use function Pest\Laravel\get;
use function Pest\Laravel\travelTo;

/*
 * S2-05 criterion 1 (authentication half): a registered passkey signs the
 * owner in, with the assertion verified end to end — challenge, origin,
 * rpIdHash, user presence and verification flags, the ES256 signature and
 * the sign counter. The emailed one-time code stays the fallback and the
 * recovery path (S1-02 §3), there is still no password route, and
 * `users.password` is never written.
 */

uses(DatabaseTransactions::class);

/** The owner account these tests act as; a function rather than a test property, which Larastan cannot see. */
function passkeyLoginOwner(): User
{
    return User::query()->where('email', OwnerAccount::EMAIL)->firstOrFail();
}

beforeEach(function (): void {
    PasskeyFixtures::trustBothOrigins();
    OwnerAccount::clear();
    OwnerAccount::create();
    app('session.store')->start();
});

afterEach(function (): void {
    PasskeyFixtures::forgetTrustedHosts();
});

it('signs the owner in with a verified assertion and records the device', function (): void {
    ['device' => $device, 'credential' => $credential] = PasskeyFixtures::register(passkeyLoginOwner());
    $passkeys = PasskeyFixtures::authenticatorOn(PasskeyFixtures::LOOPBACK_ORIGIN);

    $options = $passkeys->authenticationOptions(OwnerAccount::EMAIL);

    expect($options['rpId'])->toBe('localhost')
        ->and($options['userVerification'])->toBe('required')
        ->and($options['allowCredentials'])->toHaveCount(1);

    $assertion = $device->assert($options, PasskeyFixtures::LOOPBACK_ORIGIN, passkeyLoginOwner()->id);
    $signedIn = $passkeys->completeAuthentication($assertion);

    expect($signedIn?->id)->toBe(passkeyLoginOwner()->id)
        ->and($credential->refresh()->last_used_at)->not->toBeNull()
        ->and($credential->refresh()->sign_count)->toBe(1)
        ->and(passkeyLoginOwner()->refresh()->login_count)->toBe(1)
        ->and(passkeyLoginOwner()->refresh()->password)->toBeNull()
        ->and(AuditEvent::query()->where('action', 'auth.passkey_verified')->exists())->toBeTrue();
});

it('signs in through the login page and writes an auth_devices row for the passkey', function (): void {
    // The Livewire harness runs on APP_URL, so the ceremony belongs to that origin.
    ['device' => $device] = PasskeyFixtures::register(passkeyLoginOwner(), PasskeyFixtures::appOrigin());

    $page = Livewire::test(Login::class)->set('email', OwnerAccount::EMAIL);
    $page->call('startPasskey');

    $dispatched = collect($page->effects['dispatches'] ?? [])->firstWhere('name', 'passkey:get');
    $options = $dispatched['params']['options'] ?? [];

    expect($options)->toHaveKey('challenge');

    $page->call('completePasskey', $device->assert($options, PasskeyFixtures::appOrigin(), passkeyLoginOwner()->id))
        ->assertHasNoErrors()
        ->assertRedirect(route('home'));

    expect(Auth::guard('web')->id())->toBe(passkeyLoginOwner()->id);

    $recorded = AuthDevice::query()->where('user_id', passkeyLoginOwner()->id)->sole();

    expect($recorded->method)->toBe(LoginMethod::Passkey)
        ->and(AuditEvent::query()->where('action', 'auth.logged_in')->get()->pluck('context')->pluck('method'))->toContain('passkey');
});

it('refuses a replayed assertion: the challenge is single-use and the counter cannot go backwards', function (): void {
    ['device' => $device] = PasskeyFixtures::register(passkeyLoginOwner());
    $passkeys = PasskeyFixtures::authenticatorOn(PasskeyFixtures::LOOPBACK_ORIGIN);

    $options = $passkeys->authenticationOptions(OwnerAccount::EMAIL);
    $assertion = $device->assert($options, PasskeyFixtures::LOOPBACK_ORIGIN, passkeyLoginOwner()->id);

    expect($passkeys->completeAuthentication($assertion)?->id)->toBe(passkeyLoginOwner()->id)
        ->and($passkeys->completeAuthentication($assertion))->toBeNull('the same assertion was accepted twice');

    // A fresh challenge, but the authenticator replays an older counter value.
    $replayOptions = $passkeys->authenticationOptions(OwnerAccount::EMAIL);
    $device->rewindCounterTo(0);

    expect($passkeys->completeAuthentication($device->assert($replayOptions, PasskeyFixtures::LOOPBACK_ORIGIN, passkeyLoginOwner()->id)))->toBeNull()
        ->and(AuditEvent::query()->where('action', 'auth.passkey_login_failed')->count())->toBeGreaterThanOrEqual(2);
});

it('refuses an assertion signed for the other origin and one from an unknown credential', function (): void {
    ['device' => $device] = PasskeyFixtures::register(passkeyLoginOwner(), PasskeyFixtures::LOOPBACK_ORIGIN);
    $loopback = PasskeyFixtures::authenticatorOn(PasskeyFixtures::LOOPBACK_ORIGIN);

    $options = $loopback->authenticationOptions(OwnerAccount::EMAIL);
    $wrongOrigin = $device->assert($options, PasskeyFixtures::REMOTE_ORIGIN, passkeyLoginOwner()->id, rpId: 'localhost');

    expect($loopback->completeAuthentication($wrongOrigin))->toBeNull();

    $stranger = new VirtualAuthenticator;
    $fresh = $loopback->authenticationOptions(OwnerAccount::EMAIL);

    expect($loopback->completeAuthentication($stranger->assert($fresh, PasskeyFixtures::LOOPBACK_ORIGIN, passkeyLoginOwner()->id)))->toBeNull()
        ->and(Auth::guard('web')->check())->toBeFalse();
});

it('answers an unknown address with the same shape and no credential list', function (): void {
    PasskeyFixtures::register(passkeyLoginOwner());
    $passkeys = PasskeyFixtures::authenticatorOn(PasskeyFixtures::LOOPBACK_ORIGIN);

    $unknown = $passkeys->authenticationOptions('nobody@example.test');
    $known = $passkeys->authenticationOptions(OwnerAccount::EMAIL);

    expect(array_keys($unknown))->toEqualCanonicalizing(array_keys($known))
        ->and($unknown['allowCredentials'] ?? [])->toBe([])
        ->and($unknown['rpId'])->toBe($known['rpId']);
});

it('lets the owner fall back to the emailed code after a passkey failure', function (): void {
    Mail::fake();
    PasskeyFixtures::register(passkeyLoginOwner());

    $page = Livewire::test(Login::class)->set('email', OwnerAccount::EMAIL);
    $page->call('passkeyFailed', 'NotAllowedError')->assertHasErrors('passkey');

    $page->call('sendCode');
    $page->set('code', OwnerAccount::lastMailedCode())->call('verify')->assertRedirect(route('home'));

    expect(Auth::guard('web')->id())->toBe(passkeyLoginOwner()->id)
        ->and(AuthDevice::query()->where('user_id', passkeyLoginOwner()->id)->sole()->method)->toBe(LoginMethod::Otp)
        ->and(passkeyLoginOwner()->refresh()->password)->toBeNull();
});

it('offers the passkey button only once a passkey exists for this origin', function (): void {
    expect((string) get('/login')->getContent())->not->toContain('data-login-passkey');

    PasskeyFixtures::register(passkeyLoginOwner(), PasskeyFixtures::REMOTE_ORIGIN);

    expect((string) get('/login')->getContent())->not->toContain('data-login-passkey');

    PasskeyFixtures::register(passkeyLoginOwner(), PasskeyFixtures::appOrigin());

    expect((string) get('/login')->getContent())->toContain('data-login-passkey')
        ->and(PasskeyCredential::query()->where('user_id', passkeyLoginOwner()->id)->count())->toBe(2);
});

it('hides the passkey button on a browser that cannot run the ceremony', function (): void {
    PasskeyFixtures::register(passkeyLoginOwner(), PasskeyFixtures::appOrigin());

    Livewire::test(Login::class)
        ->assertSee('data-login-passkey', escape: false)
        ->call('passkeySupportReported', false)
        ->assertDontSee('data-login-passkey', escape: false);
});

it('stops accepting an assertion once its challenge window has passed', function (): void {
    ['device' => $device] = PasskeyFixtures::register(passkeyLoginOwner());
    $passkeys = PasskeyFixtures::authenticatorOn(PasskeyFixtures::LOOPBACK_ORIGIN);

    $options = $passkeys->authenticationOptions(OwnerAccount::EMAIL);
    travelTo(now()->addSeconds($passkeys->ceremonyTtlSeconds() + 5));

    expect($passkeys->completeAuthentication($device->assert($options, PasskeyFixtures::LOOPBACK_ORIGIN, passkeyLoginOwner()->id)))->toBeNull()
        ->and(AuditEvent::query()->where('action', 'auth.passkey_login_failed')->get()->pluck('context')->pluck('reason'))->toContain('ceremony_expired');
});
