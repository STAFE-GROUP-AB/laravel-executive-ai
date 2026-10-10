<?php

declare(strict_types=1);

use App\Livewire\Auth\Login;
use App\Models\AuditEvent;
use App\Models\User;
use App\Security\Authorization\Otp\OtpCode;
use App\Security\Authorization\Otp\OtpIssuer;
use App\Security\Authorization\Otp\OtpMail;
use App\Security\Authorization\Otp\OtpVerification;
use App\Security\Authorization\Otp\OtpVerifier;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Support\OwnerAccount;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertAuthenticatedAs;
use function Pest\Laravel\assertGuest;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\travel;

/*
 * S1-02 criterion 2 and the owner's login rule: OTP by email, never a
 * password; one mail per cooldown however often the button is pressed;
 * six digits, ten minutes, single use, five attempts then lockout.
 */

uses(DatabaseTransactions::class);

function otpOwner(): User
{
    return User::query()->where('email', OwnerAccount::EMAIL)->firstOrFail();
}

beforeEach(function (): void {
    Mail::fake();
    OwnerAccount::clear();
    OwnerAccount::create();
});

it('mails one code, verifies it and signs the owner in', function (): void {
    $page = Livewire::test(Login::class)
        ->set('email', ' Owner@Example.test ')
        ->call('sendCode')
        ->assertHasNoErrors()
        ->assertSet('step', 'code')
        ->assertSet('email', 'owner@example.test')
        ->assertSee('one-time code is on its way');

    Mail::assertSent(OtpMail::class, 1);
    Mail::assertSent(OtpMail::class, fn (OtpMail $mail): bool => $mail->hasTo('owner@example.test') && strlen($mail->code) === 6 && ctype_digit($mail->code));

    $page->set('code', OwnerAccount::lastMailedCode())
        ->call('verify')
        ->assertHasNoErrors()
        ->assertRedirect(route('home'));

    $owner = otpOwner();

    assertAuthenticatedAs($owner);

    $record = OtpCode::query()->where('user_id', $owner->id)->firstOrFail();

    expect($owner->last_login_at)->not->toBeNull()
        ->and($owner->login_count)->toBe(1)
        ->and($owner->email_verified_at)->not->toBeNull()
        ->and($record->consumed_at)->not->toBeNull()
        ->and(AuditEvent::query()->whereIn('action', ['auth.otp_issued', 'auth.otp_verified', 'auth.logged_in'])->where('actor', "user:{$owner->id}")->count())->toBe(3);
});

it('sends exactly one mail for three rapid requests and reuses the live code', function (): void {
    $page = Livewire::test(Login::class)->set('email', OwnerAccount::EMAIL);

    $page->call('sendCode');
    $page->call('sendCode');
    $page->call('sendCode');

    Mail::assertSent(OtpMail::class, 1);

    expect(OtpCode::query()->where('user_id', otpOwner()->id)->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'auth.otp_reused')->count())->toBe(2);

    travel(61)->seconds();

    $page->call('sendCode');

    Mail::assertSent(OtpMail::class, 2);

    $codes = OtpCode::query()->where('user_id', otpOwner()->id)->orderBy('created_at')->get();

    expect($codes)->toHaveCount(2)
        ->and($codes->first()->isExpired())->toBeTrue('the superseded code is expired')
        ->and($codes->last()->isExpired())->toBeFalse();
});

it('locks the code after five wrong attempts and then refuses even the right one', function (): void {
    $issued = app(OtpIssuer::class)->issue(otpOwner(), '127.0.0.1');
    $verifier = app(OtpVerifier::class);
    $right = (string) $issued->code();
    $wrong = $right === '000000' ? '111111' : '000000';

    foreach (range(1, 4) as $attempt) {
        expect($verifier->verify(otpOwner(), $wrong, '127.0.0.1'))->toBe(OtpVerification::Invalid, "attempt {$attempt}");
    }

    expect($verifier->verify(otpOwner(), $wrong, '127.0.0.1'))->toBe(OtpVerification::LockedOut)
        ->and($verifier->verify(otpOwner(), $right, '127.0.0.1'))->toBe(OtpVerification::LockedOut)
        ->and($issued->record->refresh()->attempts)->toBe(5)
        ->and($issued->record->consumed_at)->toBeNull()
        ->and(AuditEvent::query()->where('action', 'auth.otp_locked_out')->count())->toBe(2)
        ->and(AuditEvent::query()->where('action', 'auth.otp_failed')->count())->toBe(4);

    $fresh = app(OtpIssuer::class)->issue(otpOwner(), '127.0.0.1');

    expect($fresh->reused)->toBeFalse('a locked code is never reused inside the cooldown')
        ->and($verifier->verify(otpOwner(), (string) $fresh->code(), '127.0.0.1'))->toBe(OtpVerification::Verified);
});

it('rejects a code after ten minutes', function (): void {
    $issued = app(OtpIssuer::class)->issue(otpOwner(), '127.0.0.1');

    travel(10)->minutes();
    travel(1)->second();

    expect(app(OtpVerifier::class)->verify(otpOwner(), (string) $issued->code(), '127.0.0.1'))->toBe(OtpVerification::Expired);
});

it('accepts a code once only', function (): void {
    $issued = app(OtpIssuer::class)->issue(otpOwner(), '127.0.0.1');
    $verifier = app(OtpVerifier::class);

    expect($verifier->verify(otpOwner(), (string) $issued->code(), '127.0.0.1'))->toBe(OtpVerification::Verified)
        ->and($verifier->verify(otpOwner(), (string) $issued->code(), '127.0.0.1'))->toBe(OtpVerification::NoActiveCode);
});

it('ignores formatting but never a wrong length', function (): void {
    $issued = app(OtpIssuer::class)->issue(otpOwner(), '127.0.0.1');
    $code = (string) $issued->code();
    $spaced = substr($code, 0, 3).' '.substr($code, 3);

    expect(app(OtpVerifier::class)->verify(otpOwner(), $code.'1', '127.0.0.1'))->toBe(OtpVerification::Invalid)
        ->and(app(OtpVerifier::class)->verify(otpOwner(), $spaced, '127.0.0.1'))->toBe(OtpVerification::Verified);
});

it('answers an unknown address exactly like a known one and sends nothing', function (): void {
    Livewire::test(Login::class)
        ->set('email', 'nobody@example.test')
        ->call('sendCode')
        ->assertHasNoErrors()
        ->assertSet('step', 'code')
        ->assertSee('one-time code is on its way');

    Mail::assertNothingSent();

    expect(OtpCode::query()->count())->toBe(0);

    Livewire::test(Login::class)
        ->set('email', 'nobody@example.test')
        ->call('sendCode')
        ->set('code', '123456')
        ->call('verify')
        ->assertHasErrors(['code'])
        ->assertNoRedirect();

    assertGuest();
});

it('shows the verification outcome on a wrong code and keeps the user signed out', function (): void {
    $page = Livewire::test(Login::class)->set('email', OwnerAccount::EMAIL)->call('sendCode');
    $right = OwnerAccount::lastMailedCode();
    $wrong = $right === '000000' ? '111111' : '000000';

    $page->set('code', $wrong)->call('verify')
        ->assertHasErrors(['code'])
        ->assertSet('code', '')
        ->assertSee(OtpVerification::Invalid->message());

    assertGuest();

    $page->set('code', 'abc')->call('verify')->assertHasErrors(['code' => 'digits']);
});

it('rate-limits code requests per address and client', function (): void {
    $page = Livewire::test(Login::class)->set('email', OwnerAccount::EMAIL);

    foreach (range(1, 5) as $request) {
        $page->call('sendCode');
    }

    $page->call('sendCode')->assertSee('Too many codes requested');

    Mail::assertSent(OtpMail::class, 1);
});

it('renders the login page without any password field and signs out through POST /logout', function (): void {
    get('/login')->assertOk()->assertSee('Email me a code')->assertDontSee('type="password"', false);
    get('/home')->assertRedirect('/login');

    actingAs(otpOwner());

    get('/home')->assertOk()->assertSee('Signed in as');
    get('/login')->assertRedirect('/home');

    post('/logout')->assertRedirect('/login');

    assertGuest();

    expect(AuditEvent::query()->where('action', 'auth.logged_out')->where('subject_id', otpOwner()->id)->exists())->toBeTrue();
});
