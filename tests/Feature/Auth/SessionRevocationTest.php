<?php

declare(strict_types=1);

use App\Livewire\Auth\Login;
use App\Models\AuditEvent;
use App\Models\User;
use App\Security\Authorization\SessionRevoker;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Support\OwnerAccount;

use function Pest\Laravel\artisan;
use function Pest\Laravel\get;
use function Pest\Laravel\withCookie;

/*
 * S1-02 criterion 2 / threat model T-70: sessions are revocable server-side.
 * The database `sessions` table is the source of truth (ADR-006 D3), so the
 * tests switch the session driver to `database` and drive real requests
 * with the session cookie, simulating a new browser process between them.
 */

uses(DatabaseTransactions::class);

function revocationOwner(): User
{
    return User::query()->where('email', OwnerAccount::EMAIL)->firstOrFail();
}

/** Forget per-process state the test kernel keeps between requests, like a fresh PHP worker would. */
function freshBrowserProcess(): void
{
    app('auth')->forgetGuards();
    app('session')->driver()->flush();
}

function signInThroughOtp(): string
{
    Mail::fake();

    $page = Livewire::test(Login::class)->set('email', OwnerAccount::EMAIL)->call('sendCode');
    $page->set('code', OwnerAccount::lastMailedCode())->call('verify')->assertRedirect(route('home'));

    $response = get('/home')->assertOk();
    $cookie = $response->getCookie((string) config('session.cookie'));

    expect($cookie)->not->toBeNull();

    // getCookie() decrypts, so this is the plain session id; withCookie() encrypts it again on the way back in.
    return (string) $cookie?->getValue();
}

beforeEach(function (): void {
    config(['session.driver' => 'database']);
    OwnerAccount::clear();
    OwnerAccount::create();
});

it('signs the browser out on its next request once the sessions are revoked', function (): void {
    $cookieName = (string) config('session.cookie');
    $cookie = signInThroughOtp();

    expect(DB::table('sessions')->where('user_id', revocationOwner()->id)->count())->toBeGreaterThanOrEqual(1);

    freshBrowserProcess();
    withCookie($cookieName, $cookie)->get('/home')->assertOk();

    $revoked = app(SessionRevoker::class)->revokeAll(revocationOwner(), 'job:test');

    expect($revoked)->toBeGreaterThanOrEqual(1)
        ->and(DB::table('sessions')->where('user_id', revocationOwner()->id)->count())->toBe(0)
        ->and(AuditEvent::query()->where('action', 'auth.sessions_revoked')->where('subject_id', revocationOwner()->id)->exists())->toBeTrue();

    freshBrowserProcess();
    withCookie($cookieName, $cookie)->get('/home')->assertRedirect('/login');
});

it('revokes through lea:sessions:revoke by email or ULID and rotates the remember token', function (): void {
    signInThroughOtp();
    $tokenBefore = revocationOwner()->refresh()->getRememberToken();

    artisan('lea:sessions:revoke', ['user' => OwnerAccount::EMAIL])
        ->expectsOutputToContain('signed out on their next request')
        ->assertSuccessful();

    expect(DB::table('sessions')->where('user_id', revocationOwner()->id)->count())->toBe(0)
        ->and(revocationOwner()->refresh()->getRememberToken())->not->toBe($tokenBefore);

    artisan('lea:sessions:revoke', ['user' => revocationOwner()->id, '--json' => true])
        ->expectsOutputToContain('"sessions":0')
        ->assertSuccessful();

    artisan('lea:sessions:revoke', ['user' => 'nobody@example.test'])->assertFailed();
});

it('can keep the current session while ending every other one', function (): void {
    $cookieName = (string) config('session.cookie');
    $cookie = signInThroughOtp();
    $sessionIds = DB::table('sessions')->where('user_id', revocationOwner()->id)->pluck('id');
    $keep = (string) $sessionIds->last();

    $revoked = app(SessionRevoker::class)->revokeAll(revocationOwner(), 'job:test', exceptSessionId: $keep);

    expect($revoked)->toBe($sessionIds->count() - 1)
        ->and(DB::table('sessions')->where('id', $keep)->exists())->toBeTrue();

    freshBrowserProcess();
    withCookie($cookieName, $cookie)->get('/home')->assertOk();
});
