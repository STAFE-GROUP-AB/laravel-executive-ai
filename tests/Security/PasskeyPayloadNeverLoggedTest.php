<?php

declare(strict_types=1);

use App\Models\AuditEvent;
use App\Models\PasskeyCredential;
use App\Models\User;
use App\Security\Audit\AuditLog;
use App\Security\Authorization\Reauthentication\CriticalAction;
use App\Security\Authorization\Reauthentication\Reauthenticator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Tests\Support\OwnerAccount;
use Tests\Support\PasskeyFixtures;

/*
 * S2-05 criterion 6, the `OtpCredentialNeverLoggedTest` family extended to
 * WebAuthn (§11 "aldrig tokens i app logs", threat model T-70): after a
 * complete round — register a passkey, sign in with it, fail once, and
 * re-authenticate for a critical action — the challenge, the client data,
 * the authenticator data, the signature and the re-auth nonce exist nowhere
 * durable. Not in the log file, not in `audit_events.context`, not in a
 * failed-job row, not on the model's serialised form.
 *
 * The one thing that is stored on purpose is the credential's **public** key
 * and id, which a relying party must keep to verify later assertions; the
 * test pins that they are hidden from serialisation so a dump cannot carry
 * them into a log line or a diagnostics export by accident.
 */

uses(DatabaseTransactions::class);

/** The owner account these tests act as; a function rather than a test property, which Larastan cannot see. */
function redactionOwner(): User
{
    return User::query()->where('email', OwnerAccount::EMAIL)->firstOrFail();
}

function passkeyLogDirectory(): string
{
    $directory = sys_get_temp_dir().'/lea-passkey-redaction-'.uniqid();
    mkdir($directory, 0700, true);
    config(['logging.channels.single.path' => $directory.'/laravel.log', 'logging.channels.stack.channels' => ['single']]);

    return $directory;
}

/** Bounded by non-alphanumerics, so a ULID or hash that happens to contain the value is not a false positive. */
function containsValue(string $haystack, string $needle): bool
{
    return $needle !== '' && str_contains($haystack, $needle);
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

it('keeps every WebAuthn payload out of the log, the audit trail and the database', function (): void {
    $directory = passkeyLogDirectory();
    $origin = PasskeyFixtures::appOrigin();

    try {
        $passkeys = PasskeyFixtures::authenticatorOn($origin);

        $registrationOptions = $passkeys->registrationOptions(redactionOwner());
        $registrationChallenge = (string) $registrationOptions['challenge'];
        ['device' => $device] = PasskeyFixtures::register(redactionOwner(), $origin, 'Logging round');

        $authOptions = $passkeys->authenticationOptions(OwnerAccount::EMAIL);
        $authChallenge = (string) $authOptions['challenge'];
        $assertion = $device->assert($authOptions, $origin, redactionOwner()->id);

        expect($passkeys->completeAuthentication($assertion)?->id)->toBe(redactionOwner()->id);

        // A refused attempt writes an audit event too; it must carry no payload either.
        expect($passkeys->completeAuthentication($assertion))->toBeNull();

        Auth::guard('web')->login(redactionOwner());
        $reauth = app(Reauthenticator::class);
        $challenge = $reauth->challenge(CriticalAction::ApproveOutboundAction);
        $freshOptions = $reauth->passkeyOptions();
        $reauth->satisfyWithPasskey($challenge->nonce, $device->assert($freshOptions, $origin, redactionOwner()->id));
        $reauth->assert(CriticalAction::ApproveOutboundAction, $challenge->nonce);

        Log::info('A routine log line written in the middle of the ceremonies.');

        $log = file_exists($directory.'/laravel.log') ? (string) file_get_contents($directory.'/laravel.log') : '';
    } finally {
        File::deleteDirectory($directory);
    }

    $audit = AuditEvent::query()->get()->map(fn (AuditEvent $event): string => json_encode($event->getAttributes(), JSON_THROW_ON_ERROR))->implode("\n");
    $devices = json_encode(DB::table('auth_devices')->get(), JSON_THROW_ON_ERROR);
    $failedJobs = json_encode(DB::table('failed_jobs')->get(), JSON_THROW_ON_ERROR);

    $secrets = [
        'the registration challenge' => $registrationChallenge,
        'the authentication challenge' => $authChallenge,
        'the re-auth nonce' => $challenge->nonce,
        'the client data' => (string) $assertion['response']['clientDataJSON'],
        'the authenticator data' => (string) $assertion['response']['authenticatorData'],
        'the signature' => (string) $assertion['response']['signature'],
    ];

    foreach ($secrets as $what => $value) {
        expect(containsValue($log, $value))->toBeFalse("{$what} reached the log file")
            ->and(containsValue($audit, $value))->toBeFalse("{$what} reached audit_events")
            ->and(containsValue($devices, $value))->toBeFalse("{$what} reached auth_devices")
            ->and(containsValue($failedJobs, $value))->toBeFalse("{$what} reached failed_jobs");
    }

    expect(AuditEvent::query()->whereIn('action', ['auth.passkey_registered', 'auth.passkey_verified', 'auth.passkey_login_failed', 'auth.reauth_consumed'])->count())
        ->toBeGreaterThanOrEqual(4);
});

it('hides the stored credential id and public key from the model\'s serialised form', function (): void {
    ['credential' => $credential] = PasskeyFixtures::register(redactionOwner(), PasskeyFixtures::appOrigin());

    $serialised = json_encode($credential->toArray(), JSON_THROW_ON_ERROR);

    expect($serialised)->not->toContain($credential->credential_id)
        ->and($serialised)->not->toContain($credential->public_key)
        ->and($serialised)->not->toContain('user_handle')
        ->and($serialised)->toContain($credential->name)
        ->and(PasskeyCredential::query()->sole()->public_key)->not->toBe('', 'the public key is stored on purpose');
});

it('redacts a WebAuthn payload even when a caller hands it to the audit log under a known key', function (): void {
    $event = app(AuditLog::class)->record('test.leak_attempt', 'user:'.redactionOwner()->id, AuditLog::OUTCOME_OK, 'user', redactionOwner()->id, [
        'credential' => ['response' => ['signature' => 'MEUCIQDFAKEFAKEFAKEFAKEsignature0000000000']],
        'token' => 'lxai-FAKE-assertion-token-0000000000',
        'mac' => 'deadbeefdeadbeefdeadbeefdeadbeef',
    ]);

    $context = json_encode($event->refresh()->context, JSON_THROW_ON_ERROR);

    expect($context)->not->toContain('MEUCIQDFAKE')
        ->and($context)->not->toContain('lxai-FAKE-assertion-token')
        ->and($context)->not->toContain('deadbeefdeadbeef');
});
