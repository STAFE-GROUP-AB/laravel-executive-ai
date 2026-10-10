<?php

declare(strict_types=1);

namespace App\Browser\ComputerUse;

use DateTimeImmutable;

/**
 * A user-visible automation session (blueprint §6; ADR-005 D1). Created only from an
 * authenticated UI action that gives the **session grant**; never from a prompt or a
 * tool result. The grant covers `observe()` within the allowlist until `expiresAt`
 * (60 min hard), `idleTimeoutSeconds` (15 min) or `maxActions` (25) is hit, whichever
 * comes first. Each `execute()` additionally needs its own AuthorizedUiAction.
 */
final readonly class AutomationSession
{
    /**
     * @param  list<AllowlistEntry>  $allowlist
     */
    public function __construct(
        public string $id,
        public string $organizationId,
        public string $userId,
        public string $grantId,
        public array $allowlist,
        public AutomationSessionState $state,
        public DateTimeImmutable $startedAt,
        public DateTimeImmutable $expiresAt,
        public int $idleTimeoutSeconds,
        public int $maxActions,
        public int $actionsTaken,
    ) {}

    public function allows(string $bundleId): bool
    {
        foreach ($this->allowlist as $entry) {
            if ($entry->bundleId === $bundleId) {
                return true;
            }
        }

        return false;
    }

    public function isLive(DateTimeImmutable $now): bool
    {
        if ($this->state !== AutomationSessionState::Active) {
            return false;
        }

        if ($now >= $this->expiresAt) {
            return false;
        }

        return $this->actionsTaken < $this->maxActions;
    }
}
