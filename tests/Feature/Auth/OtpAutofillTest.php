<?php

declare(strict_types=1);

use App\Livewire\Auth\Login;
use App\Models\User;
use App\Security\Authorization\Otp\OtpAutofill;
use App\Security\Authorization\Otp\OtpMail;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Support\OwnerAccount;

use function Pest\Laravel\assertAuthenticatedAs;
use function Pest\Laravel\assertGuest;

/*
 * The owner's local-autofill rule: one-click login when APP_ENV=local and
 * OTP_AUTOFILL=true, and provably inert everywhere else.
 */

uses(DatabaseTransactions::class);

function autofillOwner(): User
{
    return User::query()->where('email', OwnerAccount::EMAIL)->firstOrFail();
}

beforeEach(function (): void {
    Mail::fake();
    OwnerAccount::clear();
    OwnerAccount::create();
});

it('is inert in the testing environment even when the flag is on', function (): void {
    config(['lea.auth.otp.autofill' => true]);

    expect(app(OtpAutofill::class)->isActive())->toBeFalse();

    Livewire::test(Login::class)
        ->assertSet('email', '')
        ->set('email', OwnerAccount::EMAIL)
        ->call('sendCode')
        ->assertSet('step', 'code')
        ->assertSet('code', '')
        ->assertNoRedirect();

    assertGuest();
});

it('is inert in production even when the flag is on', function (): void {
    app()['env'] = 'production';
    config(['lea.auth.otp.autofill' => true]);

    expect(app(OtpAutofill::class)->isActive())->toBeFalse();

    Livewire::test(Login::class)->set('email', OwnerAccount::EMAIL)->call('sendCode')->assertNoRedirect();

    assertGuest();
});

it('is inert in local when the flag is off', function (): void {
    app()['env'] = 'local';
    config(['lea.auth.otp.autofill' => false]);

    expect(app(OtpAutofill::class)->isActive())->toBeFalse();

    Livewire::test(Login::class)->assertSet('email', '');
});

it('pre-fills the owner address and signs in with one click in local', function (): void {
    app()['env'] = 'local';
    config(['lea.auth.otp.autofill' => true]);

    expect(app(OtpAutofill::class)->isActive())->toBeTrue();

    Livewire::test(Login::class)
        ->assertSet('email', OwnerAccount::EMAIL)
        ->call('sendCode')
        ->assertHasNoErrors()
        ->assertRedirect(route('home'));

    assertAuthenticatedAs(autofillOwner());
    Mail::assertSent(OtpMail::class, 1);
});
