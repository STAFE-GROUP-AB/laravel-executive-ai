<?php

declare(strict_types=1);

namespace App\Integrations\Mail\Dto;

use App\Integrations\Mail\Exceptions\AuthorizationRequiredReason;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Blueprint §5.6 `verify(): ConnectionHealth` — a read-only snapshot for the
 * per-account status row ("status, senaste sync, scopes, synkade mappar,
 * Disconnect/Reauthorize"). `summary` and `warnings` are user-facing sentences
 * written by the connector from categories, never provider prose, never a token
 * or an account identifier other than the connection's own address (ADR-003 D7).
 *
 * `authorizationReason` is set exactly when `state` is `AuthorizationRequired`;
 * `retryAfter` when the provider asked us to back off (`RateLimited`,
 * `Unavailable`); `quotaRemaining` is the provider's own unit (Gmail quota units,
 * Graph throttling budget, IMAP connection slots) and is for display only.
 */
final readonly class ConnectionHealth
{
    /**
     * @param  list<string>  $warnings
     * @param  list<string>  $missingScopes  requested scopes the provider did not grant (Google partial consent, Microsoft admin consent pending)
     */
    public function __construct(
        public ConnectionHealthState $state,
        public DateTimeImmutable $checkedAt,
        public string $summary,
        public ?DateTimeImmutable $lastSuccessfulSyncAt = null,
        public ?DateTimeImmutable $credentialExpiresAt = null,
        public ?DateTimeImmutable $retryAfter = null,
        public ?AuthorizationRequiredReason $authorizationReason = null,
        public ?int $quotaRemaining = null,
        public array $missingScopes = [],
        public array $warnings = [],
    ) {
        if (trim($this->summary) === '') {
            throw new InvalidArgumentException('A health report must carry a user-facing summary.');
        }

        if (($this->state === ConnectionHealthState::AuthorizationRequired) !== ($this->authorizationReason !== null)) {
            throw new InvalidArgumentException('An authorization reason is set exactly when the state is authorization_required.');
        }

        if ($this->quotaRemaining !== null && $this->quotaRemaining < 0) {
            throw new InvalidArgumentException('Remaining quota cannot be negative.');
        }
    }

    public function isUsable(): bool
    {
        return $this->state->isUsable();
    }
}
