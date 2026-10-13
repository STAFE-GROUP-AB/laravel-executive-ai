<?php

declare(strict_types=1);

use App\Livewire\Auth\Passkeys;
use App\Models\AuditEvent;
use App\Models\PasskeyCredential;
use App\Models\User;
use App\Security\Authorization\Passkeys\PasskeyAuthenticator;
use App\Security\Authorization\Passkeys\PasskeyCeremonyFailed;
use App\Security\Authorization\Passkeys\PasskeysUnavailableException;
use App\Security\Authorization\Passkeys\RelyingParty;
use App\Security\Authorization\Passkeys\WebAuthnAuthenticator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Livewire\Livewire;
use Tests\Support\OwnerAccount;
use Tests\Support\PasskeyFixtures;
use Tests\Support\VirtualAuthenticator;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\travelTo;

/*
 * S2-05 criterion 1 (registration half) / threat model T-70: the registration
 * ceremony runs for real — the virtual authenticator's attestation object is
 * verified by web-auth/webauthn-lib, not mocked — and `rp.id` is the origin
 * the request arrived on (ADR-006 D3: the loopback origin and the tailnet
 * name are different relying parties, and a credential from one is useless
 * on the other).
 */

uses(DatabaseTransactions::class);

/** The owner account these tests act as; a function rather than a test property, which Larastan cannot see. */
function registrationOwner(): User
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

it('registers a passkey whose attestation the library verifies, bound to the request origin', function (): void {
    $passkeys = PasskeyFixtures::authenticatorOn(PasskeyFixtures::REMOTE_ORIGIN);
    $options = $passkeys->registrationOptions(registrationOwner());

    expect($options['rp']['id'])->toBe('executive.example-tailnet.ts.net')
        ->and($options['challenge'])->toBeString()
        ->and(strlen((string) $options['challenge']))->toBeGreaterThan(30)
        ->and($options['authenticatorSelection']['userVerification'])->toBe('required')
        ->and($options['authenticatorSelection']['residentKey'])->toBe('required')
        ->and($options['attestation'])->toBe('none');

    $device = new VirtualAuthenticator;
    $passkeys->completeRegistration(registrationOwner(), $device->register($options, PasskeyFixtures::REMOTE_ORIGIN), 'Phone');

    $stored = PasskeyCredential::query()->where('user_id', registrationOwner()->id)->sole();

    expect($stored->name)->toBe('Phone')
        ->and($stored->rp_id)->toBe('executive.example-tailnet.ts.net')
        ->and($stored->origin)->toBe(PasskeyFixtures::REMOTE_ORIGIN)
        ->and($stored->attestation_type)->toBe('none')
        ->and($stored->transports)->toContain('internal')
        ->and($stored->user_handle)->toBe(registrationOwner()->id)
        ->and($stored->public_key)->not->toBe('')
        ->and(AuditEvent::query()->where('action', 'auth.passkey_registered')->where('subject_id', $stored->id)->exists())->toBeTrue();
});

it('gives each ADR-006 D3 origin its own relying party, so a credential never crosses origins', function (): void {
    $loopback = PasskeyFixtures::authenticatorOn(PasskeyFixtures::LOOPBACK_ORIGIN);
    $remote = PasskeyFixtures::authenticatorOn(PasskeyFixtures::REMOTE_ORIGIN);

    expect($loopback->relyingParty()->id)->toBe('localhost')
        ->and($remote->relyingParty()->id)->toBe('executive.example-tailnet.ts.net');

    $device = PasskeyFixtures::register(registrationOwner(), PasskeyFixtures::LOOPBACK_ORIGIN, 'Mac')['device'];

    // The same key, offered to the remote origin's ceremony with the loopback rpIdHash, is refused.
    $remoteOptions = $remote->registrationOptions(registrationOwner());
    $crossOrigin = $device->register($remoteOptions, PasskeyFixtures::REMOTE_ORIGIN, rpId: 'localhost');

    expect(fn () => $remote->completeRegistration(registrationOwner(), $crossOrigin))
        ->toThrow(PasskeyCeremonyFailed::class)
        ->and(PasskeyCredential::query()->where('user_id', registrationOwner()->id)->count())->toBe(1)
        ->and(PasskeyCredential::query()->where('rp_id', 'executive.example-tailnet.ts.net')->exists())->toBeFalse();
});

it('refuses a credential whose challenge was never issued, and one whose ceremony expired', function (): void {
    $passkeys = PasskeyFixtures::authenticatorOn(PasskeyFixtures::LOOPBACK_ORIGIN);
    $device = new VirtualAuthenticator;

    $forged = $device->register([
        'challenge' => 'aW52ZW50ZWQtY2hhbGxlbmdl',
        'rp' => ['id' => 'localhost'],
    ], PasskeyFixtures::LOOPBACK_ORIGIN);

    expect(fn () => $passkeys->completeRegistration(registrationOwner(), $forged))
        ->toThrow(PasskeyCeremonyFailed::class, 'no_pending_ceremony');

    $options = $passkeys->registrationOptions(registrationOwner());
    travelTo(now()->addSeconds($passkeys->ceremonyTtlSeconds() + 5));

    expect(fn () => $passkeys->completeRegistration(registrationOwner(), $device->register($options, PasskeyFixtures::LOOPBACK_ORIGIN)))
        ->toThrow(PasskeyCeremonyFailed::class, 'ceremony_expired')
        ->and(PasskeyCredential::query()->count())->toBe(0);
});

it('refuses the same passkey twice and excludes it from the next ceremony', function (): void {
    $passkeys = PasskeyFixtures::authenticatorOn(PasskeyFixtures::LOOPBACK_ORIGIN);
    $device = PasskeyFixtures::register(registrationOwner())['device'];

    $second = $passkeys->registrationOptions(registrationOwner());

    expect($second['excludeCredentials'])->toHaveCount(1);

    expect(fn () => $passkeys->completeRegistration(registrationOwner(), $device->register($second, PasskeyFixtures::LOOPBACK_ORIGIN)))
        ->toThrow(PasskeyCeremonyFailed::class, 'already_registered')
        ->and(PasskeyCredential::query()->count())->toBe(1);
});

it('reports passkeys as unavailable on an IP-literal origin rather than failing in the browser', function (): void {
    $passkeys = PasskeyFixtures::authenticatorOn('http://127.0.0.1:8000');
    $status = $passkeys->status();

    expect($passkeys->isAvailable())->toBeFalse()
        ->and($status->available)->toBeFalse()
        ->and($status->message)->toContain('127.0.0.1')
        ->and(fn () => $passkeys->registrationOptions(registrationOwner()))->toThrow(PasskeysUnavailableException::class);

    expect(PasskeyFixtures::authenticatorOn(PasskeyFixtures::LOOPBACK_ORIGIN)->isAvailable())->toBeTrue()
        ->and(PasskeyFixtures::authenticatorOn(PasskeyFixtures::REMOTE_ORIGIN)->isAvailable())->toBeTrue();
});

it('lets the owner add and remove a passkey from the security page', function (): void {
    $page = actingAs(registrationOwner())->get('/settings/security')->assertOk();

    expect($page->getContent())->toContain('data-passkeys-panel');

    $credential = PasskeyFixtures::register(registrationOwner(), PasskeyFixtures::LOOPBACK_ORIGIN, 'Laptop')['credential'];

    Livewire::actingAs(registrationOwner())
        ->test(Passkeys::class)
        ->assertSee('Laptop')
        ->call('remove', $credential->id)
        ->assertHasNoErrors();

    expect(PasskeyCredential::query()->whereKey($credential->id)->exists())->toBeFalse()
        ->and(AuditEvent::query()->where('action', 'auth.passkey_removed')->exists())->toBeTrue();
});

it('keeps the emailed code as the way in when no passkey is registered, and shows no stub row', function (): void {
    $html = (string) get('/login')->assertOk()->getContent();

    expect($html)->toContain('Email me a code')
        ->and($html)->not->toContain('Passkeys arrive in Sprint 2')
        ->and($html)->not->toContain('issues/69')
        ->and($html)->not->toContain('data-login-passkey')
        ->and(app(PasskeyAuthenticator::class))->toBeInstanceOf(WebAuthnAuthenticator::class);
});

it('derives the relying party from the request rather than configuration', function (): void {
    expect(config('lea.auth.passkeys'))->not->toHaveKey('rp_id')
        ->and(RelyingParty::fromRequest(Request::create(PasskeyFixtures::REMOTE_ORIGIN.'/home'))->id)
        ->toBe('executive.example-tailnet.ts.net');
});
