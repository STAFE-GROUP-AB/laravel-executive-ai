<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\PasskeyCredential;
use App\Models\User;
use App\Security\Audit\AuditLog;
use App\Security\Authorization\Passkeys\PendingCeremonies;
use App\Security\Authorization\Passkeys\WebAuthnAuthenticator;
use Illuminate\Http\Request;

/**
 * Shared scaffolding for the S2-05 passkey suites: an authenticator bound to
 * a chosen origin (ADR-006 D3 has two), the trusted-host list those origins
 * need, and a complete real registration ceremony so a test that is about
 * *logging in* does not have to repeat one.
 */
final class PasskeyFixtures
{
    public const string LOOPBACK_ORIGIN = 'http://localhost';

    /** A stand-in for the Tailscale Serve origin (ADR-006 D3); `.ts.net` names are real, this one is not. */
    public const string REMOTE_ORIGIN = 'https://executive.example-tailnet.ts.net';

    /**
     * `TrustLoopbackHosts` trusts the loopback names plus whatever
     * `remote.trusted_hosts` holds (ADR-006 D3; E4-1 writes the real tailnet
     * name there), and Symfony rejects any other `Host` before our code sees
     * it. A test that needs the second origin adds it to that config — the
     * same door the product uses — and also seeds the static list, because a
     * `Request` built directly never passes through the middleware.
     */
    public static function trustBothOrigins(): void
    {
        config(['remote.trusted_hosts' => ['^executive\.example\-tailnet\.ts\.net$']]);

        Request::setTrustedHosts(['^localhost$', '^127\.0\.0\.1$', '^\[::1\]$', '^executive\.example\-tailnet\.ts\.net$']);
    }

    public static function forgetTrustedHosts(): void
    {
        config(['remote.trusted_hosts' => []]);

        Request::setTrustedHosts([]);
    }

    /** The origin the test HTTP client and the Livewire harness actually use (`APP_URL`). */
    public static function appOrigin(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    /** The authenticator exactly as a request on that origin resolves it. */
    public static function authenticatorOn(string $origin): WebAuthnAuthenticator
    {
        $request = Request::create($origin.'/settings/security');
        $request->setLaravelSession(app('session.store'));

        return new WebAuthnAuthenticator($request, new PendingCeremonies(app('session.store')), app(AuditLog::class));
    }

    /**
     * Run a full registration ceremony for the user on that origin and return
     * the virtual device together with the stored row.
     *
     * @return array{device: VirtualAuthenticator, credential: PasskeyCredential}
     */
    public static function register(User $user, string $origin = self::LOOPBACK_ORIGIN, string $name = 'Test device'): array
    {
        $passkeys = self::authenticatorOn($origin);
        $device = new VirtualAuthenticator;

        $passkeys->completeRegistration($user, $device->register($passkeys->registrationOptions($user), $origin), $name);

        return [
            'device' => $device,
            'credential' => PasskeyCredential::query()->where('user_id', $user->id)->orderByDesc('created_at')->firstOrFail(),
        ];
    }
}
