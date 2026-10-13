<?php

declare(strict_types=1);

namespace App\Security\Authorization\Passkeys;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;

/**
 * The server side of one WebAuthn ceremony: the options that were handed to
 * the browser, the origin they were issued for and when they stop being
 * acceptable. Kept in the session — the same session that must complete the
 * ceremony — so a challenge can neither be replayed from another browser nor
 * outlive its window. The challenge is removed the moment it is consumed,
 * verified or not, and never copied anywhere else (auth.md §8).
 */
final class PendingCeremonies
{
    public const string REGISTRATION = 'lea.passkeys.registration';

    public const string AUTHENTICATION = 'lea.passkeys.authentication';

    public function __construct(private readonly Session $session) {}

    /** @param array<string, mixed> $options the WebAuthn JSON options as handed to the browser */
    public function store(string $kind, array $options, RelyingParty $relyingParty, int $ttlSeconds, ?string $userId = null): void
    {
        $this->session->put($kind, [
            'options' => $options,
            'origin' => $relyingParty->origin,
            'rp_id' => $relyingParty->id,
            'user_id' => $userId,
            'expires_at' => CarbonImmutable::now('UTC')->addSeconds($ttlSeconds)->getTimestamp(),
        ]);
    }

    /**
     * Take the pending ceremony out of the session (it is gone whatever the
     * verification says) and refuse it when it is missing, expired or was
     * issued for a different origin than the one completing it.
     *
     * @return array{options: array<string, mixed>, origin: string, rp_id: string, user_id: ?string}
     *
     * @throws PasskeyCeremonyFailed
     */
    public function pull(string $kind, RelyingParty $relyingParty): array
    {
        $pending = $this->session->pull($kind);

        if (! is_array($pending) || ! is_array($pending['options'] ?? null)) {
            throw new PasskeyCeremonyFailed(PasskeyCeremonyFailed::NO_PENDING_CEREMONY);
        }

        if ((int) ($pending['expires_at'] ?? 0) < CarbonImmutable::now('UTC')->getTimestamp()) {
            throw new PasskeyCeremonyFailed(PasskeyCeremonyFailed::CEREMONY_EXPIRED);
        }

        if (($pending['origin'] ?? null) !== $relyingParty->origin || ($pending['rp_id'] ?? null) !== $relyingParty->id) {
            throw new PasskeyCeremonyFailed(PasskeyCeremonyFailed::ORIGIN_MISMATCH);
        }

        return [
            'options' => $pending['options'],
            'origin' => (string) $pending['origin'],
            'rp_id' => (string) $pending['rp_id'],
            'user_id' => is_string($pending['user_id'] ?? null) ? $pending['user_id'] : null,
        ];
    }

    public function forget(string $kind): void
    {
        $this->session->forget($kind);
    }
}
