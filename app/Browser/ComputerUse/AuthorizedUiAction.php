<?php

declare(strict_types=1);

namespace App\Browser\ComputerUse;

use DateTimeImmutable;

/**
 * Blueprint §3 `execute(AuthorizedUiAction)`: a UiAction plus the proof that a human
 * approved exactly this action (ADR-005 D1.6; §11 frozen immutable proposal).
 *
 * **Only the policy layer (`App\Approvals`) constructs this class**, after matching
 * an `action_proposals` row to its `action_approvals` row. The agent loop, tools,
 * MCP handlers and anything that touches model output or external text may not
 * instantiate it; `tests/Unit/Contracts/AuthorizedActionProvenanceTest.php` enforces
 * that with a source scan, and S0-21 builds the negative security test on it.
 *
 * `proposalHash` is the SHA-256 over the frozen proposal (session, app, window,
 * element, action kind, redacted value hash). The provider recomputes it from the
 * action it is about to run and refuses on mismatch, so an approval can never be
 * replayed against a different action.
 */
final readonly class AuthorizedUiAction
{
    public function __construct(
        public string $proposalId,
        public string $approvalId,
        public string $approverUserId,
        public string $sessionId,
        public string $bundleId,
        public string $windowTitle,
        public UiAction $action,
        public string $proposalHash,
        public string $nonce,
        public DateTimeImmutable $approvedAt,
        public DateTimeImmutable $expiresAt,
    ) {}

    public function isExpired(DateTimeImmutable $now): bool
    {
        return $now >= $this->expiresAt;
    }
}
