<?php

declare(strict_types=1);

use App\Livewire\Auth\Devices;
use App\Livewire\Auth\Login;
use App\Models\AuditEvent;
use App\Models\AuthDevice;
use App\Models\User;
use App\Security\Authorization\Devices\DeviceLabel;
use App\Security\Authorization\Devices\DeviceRegistry;
use App\Security\Authorization\Devices\LoginMethod;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Support\OwnerAccount;

use function Pest\Laravel\get;
use function Pest\Laravel\withCookie;

/*
 * S2-05 criterion 2 / threat model T-70: the Devices page lists every live
 * session and revokes one — or every other one — over S1-02's SessionRevoker
 * (auth.md §5), which stays the only code that deletes session rows. The
 * first test drives a real cookie across a simulated new PHP process, so
 * "signed out on its next request" is observed rather than asserted about.
 */

uses(DatabaseTransactions::class);

function deviceOwner(): User
{
    return User::query()->where('email', OwnerAccount::EMAIL)->firstOrFail();
}

/** Forget per-process state the test kernel keeps between requests, like a fresh PHP worker would. */
function freshProcess(): void
{
    app('auth')->forgetGuards();
    app('session')->driver()->flush();
}

/**
 * Sign in through the emailed code and hand back the browser's session id
 * (the decrypted cookie value), then record the device for exactly that
 * session — which is what the login page does in a real browser, where the
 * Livewire request and the next page load share one session.
 */
function signInAndRecordDevice(string $userAgent = 'Mozilla/5.0 (Macintosh) Chrome/131.0 Safari/537.36'): string
{
    Mail::fake();

    $page = Livewire::test(Login::class)->set('email', OwnerAccount::EMAIL)->call('sendCode');
    $page->set('code', OwnerAccount::lastMailedCode())->call('verify')->assertRedirect(route('home'));

    $cookie = get('/home')->assertOk()->getCookie((string) config('session.cookie'));

    expect($cookie)->not->toBeNull();

    $sessionId = (string) $cookie?->getValue();

    app(DeviceRegistry::class)->record(
        deviceOwner(),
        Request::create('/home', 'GET', server: ['HTTP_USER_AGENT' => $userAgent, 'REMOTE_ADDR' => '127.0.0.1']),
        LoginMethod::Otp,
    );

    return $sessionId;
}

beforeEach(function (): void {
    config(['session.driver' => 'database']);
    OwnerAccount::clear();
    OwnerAccount::create();
});

it('signs a revoked device out on its next request', function (): void {
    $cookieName = (string) config('session.cookie');
    $sessionId = signInAndRecordDevice();
    $owner = deviceOwner();

    $device = AuthDevice::query()->where('user_id', $owner->id)->where('session_id', $sessionId)->sole();

    expect($device->method)->toBe(LoginMethod::Otp)
        ->and($device->label)->toBe('Chrome · Mac')
        ->and(app(DeviceRegistry::class)->active($owner, $sessionId)->pluck('id'))->toContain($device->id);

    freshProcess();
    withCookie($cookieName, $sessionId)->get('/home')->assertOk();

    expect(app(DeviceRegistry::class)->revoke($owner, $device->id, "user:{$owner->id}"))->toBeTrue()
        ->and($device->refresh()->revoked_at)->not->toBeNull()
        ->and(DB::table('sessions')->where('id', $sessionId)->exists())->toBeFalse()
        ->and(AuditEvent::query()->where('action', 'auth.device_revoked')->exists())->toBeTrue()
        ->and(AuditEvent::query()->where('action', 'auth.session_revoked')->exists())->toBeTrue('the registry went through SessionRevoker');

    freshProcess();
    withCookie($cookieName, $sessionId)->get('/home')->assertRedirect('/login');
});

it('lists only sessions that are still alive and marks the current one', function (): void {
    $sessionId = signInAndRecordDevice();
    $owner = deviceOwner();
    $registry = app(DeviceRegistry::class);

    expect($registry->active($owner, $sessionId))->toHaveCount(1)
        ->and($registry->active($owner, $sessionId)->first()->current)->toBeTrue()
        ->and($registry->active($owner, 'another-session')->first()->current)->toBeFalse();

    // A device row whose session is gone is not listed: it is already signed out.
    AuthDevice::query()->create([
        'user_id' => $owner->id,
        'session_id' => 'session-that-no-longer-exists',
        'method' => LoginMethod::Passkey,
        'label' => 'Safari · iPhone',
        'signed_in_at' => now(),
    ]);

    expect($registry->active($owner, $sessionId))->toHaveCount(1);
});

it('revokes every other device while keeping the current one', function (): void {
    $sessionId = signInAndRecordDevice();
    $owner = deviceOwner();

    // A second browser: its own session row and its own device row.
    DB::table('sessions')->insert([
        'id' => 'second-browser-session',
        'user_id' => $owner->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Mozilla/5.0 (iPhone) Safari/605',
        'payload' => base64_encode(serialize([])),
        'last_activity' => now()->getTimestamp(),
    ]);
    AuthDevice::query()->create([
        'user_id' => $owner->id,
        'session_id' => 'second-browser-session',
        'method' => LoginMethod::Passkey,
        'label' => 'Safari · iPhone',
        'signed_in_at' => now(),
    ]);

    $registry = app(DeviceRegistry::class);

    expect($registry->active($owner, $sessionId))->toHaveCount(2);

    $revoked = $registry->revokeOthers($owner, $sessionId, "user:{$owner->id}");

    expect($revoked)->toBe(1)
        ->and(DB::table('sessions')->where('id', 'second-browser-session')->exists())->toBeFalse()
        ->and(DB::table('sessions')->where('id', $sessionId)->exists())->toBeTrue()
        ->and($registry->active($owner, $sessionId))->toHaveCount(1)
        ->and(AuthDevice::query()->where('session_id', 'second-browser-session')->sole()->revoked_at)->not->toBeNull()
        ->and(AuditEvent::query()->where('action', 'auth.sessions_revoked')->exists())->toBeTrue();
});

it('refuses to revoke another user\'s device', function (): void {
    $sessionId = signInAndRecordDevice();
    $owner = deviceOwner();
    $device = AuthDevice::query()->where('user_id', $owner->id)->where('session_id', $sessionId)->sole();
    $stranger = User::factory()->create();

    expect(app(DeviceRegistry::class)->revoke($stranger, $device->id, "user:{$stranger->id}"))->toBeFalse()
        ->and($device->refresh()->revoked_at)->toBeNull()
        ->and(app(DeviceRegistry::class)->revoke($owner, 'a-device-that-does-not-exist', "user:{$owner->id}"))->toBeFalse();
});

it('refuses, on the page, to revoke the device making the request', function (): void {
    $sessionId = signInAndRecordDevice();
    $owner = deviceOwner();
    $device = AuthDevice::query()->where('user_id', $owner->id)->where('session_id', $sessionId)->sole();

    // The page runs on the same session the device row carries, so this row is "current".
    app('session.store')->setId($device->session_id);

    Livewire::actingAs($owner)
        ->test(Devices::class)
        ->call('revoke', $device->id)
        ->assertHasErrors('device');

    expect($device->refresh()->revoked_at)->toBeNull()
        ->and(DB::table('sessions')->where('id', $device->session_id)->exists())->toBeTrue();
});

it('labels browsers without carrying the whole User-Agent into the label', function (): void {
    expect(DeviceLabel::fromUserAgent('Mozilla/5.0 (iPhone; CPU iPhone OS 18_0) Safari/605.1.15'))->toBe('Safari · iPhone')
        ->and(DeviceLabel::fromUserAgent('Mozilla/5.0 (Macintosh) Chrome/131.0 Safari/537.36'))->toBe('Chrome · Mac')
        ->and(DeviceLabel::fromUserAgent('Mozilla/5.0 (Windows NT 10.0) Firefox/133.0'))->toBe('Firefox · Windows')
        ->and(DeviceLabel::fromUserAgent('LaravelExecutiveAI/1.0 (Macintosh)'))->toBe('Mac app · Mac')
        ->and(DeviceLabel::fromUserAgent(null))->toBe('Unrecognised browser');
});

it('keeps the full User-Agent on the row but never in the view model or a serialised device', function (): void {
    $sessionId = signInAndRecordDevice();
    $owner = deviceOwner();
    $device = AuthDevice::query()->where('user_id', $owner->id)->where('session_id', $sessionId)->sole();

    $view = app(DeviceRegistry::class)->active($owner, $sessionId)->sole();

    expect($device->user_agent)->toContain('537.36')
        ->and(json_encode($view, JSON_THROW_ON_ERROR))->not->toContain('537.36')
        ->and(json_encode($device->toArray(), JSON_THROW_ON_ERROR))->not->toContain($device->session_id);
});

it('shows the devices panel on the security page', function (): void {
    signInAndRecordDevice();

    $page = get('/settings/security')->assertOk();
    $html = (string) Livewire::actingAs(deviceOwner())->test(Devices::class)->html();

    expect((string) $page->getContent())->toContain('data-devices-panel')
        ->and($html)->toContain('data-device-list')
        ->and((string) $page->headers->get('Cache-Control'))->toContain('no-store');
});
