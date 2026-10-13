<?php

declare(strict_types=1);

namespace App\Security\Authorization\Reauthentication;

use App\Models\User;
use App\Security\Audit\AuditLog;
use App\Security\Authorization\Devices\LoginMethod;
use App\Security\Authorization\Otp\IssuedOtp;
use App\Security\Authorization\Otp\OtpIssuer;
use App\Security\Authorization\Otp\OtpVerification;
use App\Security\Authorization\Otp\OtpVerifier;
use App\Security\Authorization\Passkeys\PasskeyAuthenticator;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use ParagonIE\ConstantTime\Base64UrlSafe;
use SensitiveParameter;

/**
 * Re-authentication for critical actions (§11, threat model T-71,
 * docs/architecture/auth.md §7.4). The PWA-side primitive; the Supervisor's
 * elevation (ipc-contract §7.3) is the IPC-side one.
 *
 * The flow a consumer (S2-08 approvals, S2-19 admin, S2-14 privacy downgrade)
 * follows:
 *
 *   1. `challenge($action)`           → a nonce bound to the action, kept in the session
 *   2. the `Reauthenticate` component  → the owner proves presence with a passkey
 *                                        or the emailed code; `satisfyWith*()` marks the nonce
 *   3. `assert($action, $nonce)`       → returns the proof once and spends it, or throws
 *
 * A proof is bound to the session, the action and the nonce, and is good for
 * `window_seconds` after verification. A merely fresh session has no proof;
 * a proof for another action or another nonce is not a proof for this one;
 * a proof is spent by the first `assert()` that accepts it. Nothing here
 * writes the code or the WebAuthn payload anywhere.
 */
final class Reauthenticator
{
    public const string SESSION_KEY = 'lea.reauth';

    public function __construct(
        private readonly Session $session,
        private readonly Guard $auth,
        private readonly Request $request,
        private readonly OtpIssuer $otpIssuer,
        private readonly OtpVerifier $otpVerifier,
        private readonly PasskeyAuthenticator $passkeys,
        private readonly AuditLog $audit,
    ) {}

    public function challenge(CriticalAction $action): ReauthChallenge
    {
        $user = $this->requireUser();
        $now = CarbonImmutable::now('UTC');
        $nonce = Base64UrlSafe::encodeUnpadded(random_bytes(24));
        $expiresAt = $now->addSeconds($this->challengeTtlSeconds());

        $entries = $this->entries();
        $entries[$nonce] = [
            'action' => $action->value,
            'issued_at' => $now->getTimestamp(),
            'challenge_expires_at' => $expiresAt->getTimestamp(),
            'verified_at' => null,
            'proof_expires_at' => null,
            'method' => null,
            'consumed_at' => null,
        ];
        $this->session->put(self::SESSION_KEY, $entries);

        $this->audit->record('auth.reauth_challenged', "user:{$user->id}", AuditLog::OUTCOME_OK, 'user', $user->id, ['action' => $action->value]);

        return new ReauthChallenge($action, $nonce, $expiresAt);
    }

    /** The emailed code as the fallback: issue (or reuse, S1-02's double-send rule) a code for the signed-in user. */
    public function sendCode(): IssuedOtp
    {
        return $this->otpIssuer->issue($this->requireUser(), $this->request->ip());
    }

    public function satisfyWithOtp(string $nonce, #[SensitiveParameter] string $code): OtpVerification
    {
        $user = $this->requireUser();

        if (! $this->isAnswerable($nonce)) {
            return OtpVerification::NoActiveCode;
        }

        $result = $this->otpVerifier->verify($user, $code, $this->request->ip());

        if ($result->succeeded()) {
            $this->markVerified($user, $nonce, LoginMethod::Otp);
        }

        return $result;
    }

    /** @return array<string, mixed> WebAuthn request options for the signed-in user */
    public function passkeyOptions(): array
    {
        return $this->passkeys->authenticationOptions($this->requireUser()->email);
    }

    /** @param array<string, mixed> $credential */
    public function satisfyWithPasskey(string $nonce, array $credential): bool
    {
        $user = $this->requireUser();

        if (! $this->isAnswerable($nonce)) {
            return false;
        }

        $verified = $this->passkeys->completeAuthentication($credential);

        if ($verified === null || $verified->id !== $user->id) {
            $this->audit->record('auth.reauth_refused', "user:{$user->id}", AuditLog::OUTCOME_REFUSED, 'user', $user->id, ['reason' => 'passkey_not_verified']);

            return false;
        }

        $this->markVerified($user, $nonce, LoginMethod::Passkey);

        return true;
    }

    /** Whether a proof for this action and nonce would be accepted right now, without spending it. */
    public function passes(CriticalAction $action, string $nonce): bool
    {
        return $this->failureReason($action, $nonce) === null;
    }

    /**
     * Spend the proof for this action and nonce, or refuse.
     *
     * @throws ReauthenticationRequired
     */
    public function assert(CriticalAction $action, string $nonce): ReauthProof
    {
        $user = $this->requireUser();
        $reason = $this->failureReason($action, $nonce);

        if ($reason !== null) {
            $this->audit->record('auth.reauth_refused', "user:{$user->id}", AuditLog::OUTCOME_REFUSED, 'user', $user->id, ['action' => $action->value, 'reason' => $reason]);

            throw new ReauthenticationRequired($action, $reason);
        }

        $entries = $this->entries();
        $entry = $entries[$nonce];
        $now = CarbonImmutable::now('UTC');
        $entries[$nonce]['consumed_at'] = $now->getTimestamp();
        $this->session->put(self::SESSION_KEY, $entries);

        $method = LoginMethod::from((string) $entry['method']);

        $this->audit->record('auth.reauth_consumed', "user:{$user->id}", AuditLog::OUTCOME_OK, 'user', $user->id, ['action' => $action->value, 'method' => $method->value]);

        return new ReauthProof(
            $action,
            $nonce,
            $method,
            CarbonImmutable::createFromTimestampUTC((int) $entry['verified_at']),
            CarbonImmutable::createFromTimestampUTC((int) $entry['proof_expires_at']),
        );
    }

    public function windowSeconds(): int
    {
        return max(10, (int) config('lea.auth.reauth.window_seconds', 120));
    }

    public function challengeTtlSeconds(): int
    {
        return max(30, (int) config('lea.auth.reauth.challenge_ttl_seconds', 300));
    }

    private function failureReason(CriticalAction $action, string $nonce): ?string
    {
        $entry = $this->entries()[$nonce] ?? null;

        if (! is_array($entry)) {
            return ReauthenticationRequired::MISSING;
        }

        if (($entry['action'] ?? null) !== $action->value) {
            return ReauthenticationRequired::ACTION_MISMATCH;
        }

        if ($entry['verified_at'] === null) {
            return ReauthenticationRequired::NOT_VERIFIED;
        }

        if ($entry['consumed_at'] !== null) {
            return ReauthenticationRequired::CONSUMED;
        }

        if ((int) $entry['proof_expires_at'] < CarbonImmutable::now('UTC')->getTimestamp()) {
            return ReauthenticationRequired::EXPIRED;
        }

        return null;
    }

    private function isAnswerable(string $nonce): bool
    {
        $entry = $this->entries()[$nonce] ?? null;

        if (! is_array($entry) || $entry['verified_at'] !== null) {
            return false;
        }

        return (int) $entry['challenge_expires_at'] >= CarbonImmutable::now('UTC')->getTimestamp();
    }

    private function markVerified(User $user, string $nonce, LoginMethod $method): void
    {
        $now = CarbonImmutable::now('UTC');
        $entries = $this->entries();
        $entries[$nonce]['verified_at'] = $now->getTimestamp();
        $entries[$nonce]['proof_expires_at'] = $now->addSeconds($this->windowSeconds())->getTimestamp();
        $entries[$nonce]['method'] = $method->value;
        $this->session->put(self::SESSION_KEY, $entries);

        $this->audit->record('auth.reauth_verified', "user:{$user->id}", AuditLog::OUTCOME_OK, 'user', $user->id, [
            'action' => $entries[$nonce]['action'],
            'method' => $method->value,
            'ip_address' => $this->request->ip(),
        ]);
    }

    /**
     * The session's challenge table, with entries nobody can use any more pruned.
     *
     * @return array<string, array<string, mixed>>
     */
    private function entries(): array
    {
        $raw = $this->session->get(self::SESSION_KEY, []);
        $now = CarbonImmutable::now('UTC')->getTimestamp();
        $entries = [];

        foreach (is_array($raw) ? $raw : [] as $nonce => $entry) {
            if (! is_array($entry) || ! is_string($nonce)) {
                continue;
            }

            $deadline = $entry['verified_at'] === null ? (int) $entry['challenge_expires_at'] : (int) $entry['proof_expires_at'];

            if ($deadline + 60 < $now) {
                continue;
            }

            $entries[$nonce] = $entry;
        }

        return $entries;
    }

    private function requireUser(): User
    {
        $user = $this->auth->user();

        if (! $user instanceof User) {
            throw new ReauthenticationRequired(CriticalAction::ApproveOutboundAction, ReauthenticationRequired::MISSING);
        }

        return $user;
    }
}
