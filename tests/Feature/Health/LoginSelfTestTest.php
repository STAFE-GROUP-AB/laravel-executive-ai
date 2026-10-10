<?php

declare(strict_types=1);

use App\Models\AuditEvent;
use App\Models\Membership;
use App\Security\Authorization\Otp\OtpCode;
use App\Security\Authorization\Otp\OtpIssuer;
use App\Security\Authorization\Otp\OtpVerification;
use App\Security\Authorization\Otp\OtpVerifier;
use App\Support\Health\Checks\AuthLoginCheck;
use App\Support\Health\CheckStatus;
use App\Support\Health\Heartbeats;
use App\Support\Health\LoginSelfTest;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\OwnerAccount;

/*
 * Health contract §2 `auth_login`: the self-test issues and verifies a real
 * one-time code through the real services, without mail and without leaving
 * a trace in the owner's live login state.
 */

uses(DatabaseTransactions::class);

beforeEach(function (): void {
    OwnerAccount::clear();
    app(Heartbeats::class)->forget(Heartbeats::LOGIN_SELFTEST);
});

it('passes the owner, session and otp round-trips and records each part', function (): void {
    OwnerAccount::create();

    expect(app(LoginSelfTest::class)->run())->toBeTrue();

    $heartbeat = app(Heartbeats::class)->read(Heartbeats::LOGIN_SELFTEST);

    expect($heartbeat)->toMatchArray(['passed' => true, 'owner' => true, 'session' => true, 'otp' => true])
        ->and(app(AuthLoginCheck::class)->run()->status)->toBe(CheckStatus::Ok);
});

it('leaves the owner live code, attempts and audit trail exactly as they were', function (): void {
    $owner = OwnerAccount::create();
    $live = app(OtpIssuer::class)->mint($owner, '127.0.0.1');
    app(OtpVerifier::class)->verify($owner, $live->code() === '000000' ? '111111' : '000000', '127.0.0.1');

    $codesBefore = OtpCode::query()->where('user_id', $owner->id)->count();
    $auditBefore = AuditEvent::query()->count();
    $expiresBefore = $live->record->refresh()->expires_at;

    expect(app(LoginSelfTest::class)->run())->toBeTrue();

    $record = $live->record->refresh();

    expect(OtpCode::query()->where('user_id', $owner->id)->count())->toBe($codesBefore)
        ->and(AuditEvent::query()->count())->toBe($auditBefore)
        ->and($record->attempts)->toBe(1)
        ->and($record->consumed_at)->toBeNull()
        ->and($record->expires_at->equalTo($expiresBefore))->toBeTrue()
        ->and(app(OtpVerifier::class)->verify($owner, $live->code(), '127.0.0.1'))->toBe(OtpVerification::Verified)
        ->and($owner->refresh()->login_count)->toBe(1, 'the self-test never counted as a login');
});

it('fails without an owner and the check says so', function (): void {
    expect(Membership::query()->exists())->toBeFalse()
        ->and(app(LoginSelfTest::class)->run())->toBeFalse()
        ->and(app(Heartbeats::class)->read(Heartbeats::LOGIN_SELFTEST))->toMatchArray(['passed' => false, 'owner' => false, 'session' => false, 'otp' => false]);

    $outcome = app(AuthLoginCheck::class)->run();

    expect($outcome->status)->toBe(CheckStatus::Fail)
        ->and($outcome->detail)->toContain('no owner account');
});

it('names the failed part when a recorded self-test did not pass', function (): void {
    OwnerAccount::create();
    app(Heartbeats::class)->record(Heartbeats::LOGIN_SELFTEST, ['passed' => false, 'owner' => true, 'session' => true, 'otp' => false]);

    $outcome = app(AuthLoginCheck::class)->run();

    expect($outcome->status)->toBe(CheckStatus::Fail)
        ->and($outcome->detail)->toContain('login self-test failed (otp round-trip)');
});
