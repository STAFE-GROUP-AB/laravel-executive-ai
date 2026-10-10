<?php

declare(strict_types=1);

use App\Livewire\Auth\Login;
use App\Models\AuditEvent;
use App\Security\Audit\AuditLog;
use App\Security\Authorization\Otp\OtpCode;
use App\Security\Authorization\Otp\OtpMail;
use App\Support\Health\Heartbeats;
use App\Support\Health\LoginSelfTest;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Support\OwnerAccount;

use function Pest\Laravel\post;

/*
 * S1-02 criterion 3 / threat model T-70 (§11 "never tokens in app logs"):
 * after a complete owner-creation and login round, including a wrong
 * attempt, a logout and the health self-test, the one-time code exists in
 * exactly one place — the single outgoing mail. Not in the log file, not in
 * `audit_events.context`, not in the database, not in the cache, not in any
 * command output or rendered page.
 */

uses(DatabaseTransactions::class);

function otpLogDirectory(): string
{
    $directory = sys_get_temp_dir().'/lea-otp-redaction-'.uniqid();
    mkdir($directory, 0700, true);
    config(['logging.channels.single.path' => $directory.'/laravel.log', 'logging.channels.stack.channels' => ['single']]);

    return $directory;
}

/** Bounded by non-alphanumerics, so a ULID or hash that happens to contain the digits is not a false positive. */
function containsCode(string $haystack, string $code): bool
{
    return preg_match('/(?<![0-9A-Za-z])'.preg_quote($code, '/').'(?![0-9A-Za-z])/', $haystack) === 1;
}

it('writes the code to the outgoing mail and nowhere else', function (): void {
    $directory = otpLogDirectory();
    Mail::fake();
    OwnerAccount::clear();

    try {
        Artisan::call('lea:owner:create', ['--name' => 'Ada Owner', '--email' => OwnerAccount::EMAIL, '--organization' => 'Example Organization', '--json' => true]);
        $commandOutput = Artisan::output();

        $page = Livewire::test(Login::class)->set('email', OwnerAccount::EMAIL)->call('sendCode');
        $afterSend = $page->html();
        $code = OwnerAccount::lastMailedCode();
        $wrong = $code === '000000' ? '111111' : '000000';

        $afterWrong = $page->set('code', $wrong)->call('verify')->html();
        $page->set('code', $code)->call('verify')->assertRedirect(route('home'));

        post('/logout');
        app(LoginSelfTest::class)->run();

        $log = file_exists($directory.'/laravel.log') ? (string) file_get_contents($directory.'/laravel.log') : '';
    } finally {
        File::deleteDirectory($directory);
    }

    $audit = AuditEvent::query()->get()->map(fn (AuditEvent $event): string => json_encode($event->getAttributes(), JSON_THROW_ON_ERROR))->implode("\n");
    $otpRows = json_encode(DB::table('otp_codes')->get(), JSON_THROW_ON_ERROR);
    $userRows = json_encode(DB::table('users')->get(), JSON_THROW_ON_ERROR);
    $heartbeat = json_encode(Cache::get(Heartbeats::key(Heartbeats::LOGIN_SELFTEST)), JSON_THROW_ON_ERROR);

    Mail::assertSent(OtpMail::class, 1);

    expect(strlen($code))->toBe(6)
        ->and(containsCode($log, $code))->toBeFalse('the code reached the log file')
        ->and(containsCode($audit, $code))->toBeFalse('the code reached audit_events')
        ->and(containsCode($otpRows, $code))->toBeFalse('the code is stored in plaintext')
        ->and(containsCode($userRows, $code))->toBeFalse()
        ->and(containsCode($heartbeat, $code))->toBeFalse()
        ->and(containsCode($commandOutput, $code))->toBeFalse()
        ->and(containsCode($afterSend, $code))->toBeFalse('the code was rendered into the page after sending')
        ->and(containsCode($afterWrong, $code))->toBeFalse()
        ->and(OtpCode::query()->count())->toBeGreaterThanOrEqual(1)
        ->and(AuditEvent::query()->whereIn('action', ['auth.otp_issued', 'auth.otp_failed', 'auth.otp_verified', 'auth.logged_out'])->count())->toBe(4);
});

it('keeps the code out of the audit context even if a caller passes it under a known key', function (): void {
    OwnerAccount::clear();
    $owner = OwnerAccount::create();

    $event = app(AuditLog::class)->record('test.leak_attempt', "user:{$owner->id}", 'ok', 'user', $owner->id, ['otp' => '482913', 'one_time_code' => '482913', 'note' => 'otp=482913']);

    expect(json_encode($event->refresh()->context, JSON_THROW_ON_ERROR))->not->toContain('482913');
});
