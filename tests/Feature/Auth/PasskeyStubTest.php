<?php

declare(strict_types=1);

use App\Models\User;
use App\Security\Authorization\Passkeys\NotYetAvailable;
use App\Security\Authorization\Passkeys\PasskeyAuthenticator;
use App\Security\Authorization\Passkeys\PasskeysUnavailableException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\OwnerAccount;

use function Pest\Laravel\get;

/*
 * S1-02 criterion 5: passkeys are a stub with a linked Sprint 2 issue, not a
 * silent omission. E2-3 (#69) deletes this file together with the stub.
 */

uses(DatabaseTransactions::class);

it('binds the NotYetAvailable stub and says so', function (): void {
    $passkeys = app(PasskeyAuthenticator::class);
    $status = $passkeys->status();

    expect($passkeys)->toBeInstanceOf(NotYetAvailable::class)
        ->and($passkeys->isAvailable())->toBeFalse()
        ->and($status->available)->toBeFalse()
        ->and($status->message)->toBe('Passkeys arrive in Sprint 2 (E2-3)')
        ->and($status->trackingUrl)->toEndWith('/issues/69');
});

it('refuses every ceremony with an exception rather than a silent no-op', function (): void {
    $passkeys = app(PasskeyAuthenticator::class);
    $user = User::factory()->make();

    expect(fn () => $passkeys->registrationOptions($user))->toThrow(PasskeysUnavailableException::class)
        ->and(fn () => $passkeys->completeRegistration($user, []))->toThrow(PasskeysUnavailableException::class)
        ->and(fn () => $passkeys->authenticationOptions('owner@example.test'))->toThrow(PasskeysUnavailableException::class)
        ->and(fn () => $passkeys->completeAuthentication([]))->toThrow(PasskeysUnavailableException::class);
});

it('shows the passkey status row on the login page', function (): void {
    OwnerAccount::clear();
    OwnerAccount::create();

    get('/login')->assertOk()->assertSee('Passkeys arrive in Sprint 2 (E2-3)')->assertSee('issues/69');
});
