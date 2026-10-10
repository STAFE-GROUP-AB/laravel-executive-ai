<?php

declare(strict_types=1);

namespace App\Support\Health;

use App\Models\Membership;
use App\Models\MembershipRole;
use App\Models\User;
use App\Security\Authorization\Otp\OtpIssuer;
use App\Security\Authorization\Otp\OtpVerifier;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Cookie\CookieJar;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Throwable;

/**
 * The login self-test behind `auth_login` (health contract §2). Three parts,
 * each recorded separately in the heartbeat so AuthLoginCheck can say which
 * one failed:
 *
 *  - owner:   an owner membership exists and the web guard's user provider
 *             resolves that user;
 *  - session: a SessionGuard built over an isolated in-memory session and a
 *             synthetic loopback request logs the owner in, reports them
 *             authenticated and logs them out again — the real guard and the
 *             real session store are never touched;
 *  - otp:     the real OtpIssuer mints a code (no mail) and the real
 *             OtpVerifier verifies it, inside a transaction that is always
 *             rolled back, so the owner's live codes, attempts and audit
 *             trail are exactly as they were. The issuer has no rate limit
 *             for the same reason.
 *
 * Run by `health:selftest` every minute, never on request.
 */
final class LoginSelfTest
{
    public function __construct(
        private readonly AuthFactory $auth,
        private readonly Heartbeats $heartbeats,
        private readonly OtpIssuer $issuer,
        private readonly OtpVerifier $verifier,
        private readonly DatabaseManager $db,
    ) {}

    public function run(): bool
    {
        $owner = $this->resolveOwner();
        $session = $owner !== null && $this->sessionRoundTrip($owner);
        $otp = $owner !== null && $this->otpRoundTrip($owner);
        $passed = $owner !== null && $session && $otp;

        $this->heartbeats->record(Heartbeats::LOGIN_SELFTEST, [
            'passed' => $passed,
            'owner' => $owner !== null,
            'session' => $session,
            'otp' => $otp,
        ]);

        return $passed;
    }

    private function resolveOwner(): ?User
    {
        try {
            $owner = Membership::query()->where('role', MembershipRole::Owner->value)->orderBy('created_at')->first();

            if ($owner === null) {
                return null;
            }

            $user = $this->auth->guard('web')->getProvider()->retrieveById($owner->user_id);

            return $user instanceof User ? $user : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function sessionRoundTrip(User $owner): bool
    {
        try {
            $request = Request::create('/login', 'POST', [], [], [], ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_USER_AGENT' => 'lea-login-selftest']);
            $session = new Store('lea-login-selftest', new ArraySessionHandler(1));
            $session->start();
            $request->setLaravelSession($session);

            $guard = new SessionGuard('web', $this->auth->guard('web')->getProvider(), $session, $request);
            $guard->setCookieJar(new CookieJar);
            $guard->login($owner);

            $authenticated = $guard->check() && $guard->id() === $owner->id;

            $guard->logout();

            return $authenticated && ! $guard->check();
        } catch (Throwable) {
            return false;
        }
    }

    private function otpRoundTrip(User $owner): bool
    {
        try {
            $this->db->beginTransaction();

            try {
                $minted = $this->issuer->mint($owner, '127.0.0.1');
                $result = $this->verifier->verify($owner, $minted->code(), '127.0.0.1');

                return $result->succeeded();
            } finally {
                $this->db->rollBack();
            }
        } catch (Throwable) {
            return false;
        }
    }
}
