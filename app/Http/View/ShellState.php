<?php

declare(strict_types=1);

namespace App\Http\View;

use App\Security\Authorization\Passkeys\PasskeyStatus;
use App\Support\Health\AiState;

/**
 * What every PWA page's chrome shows: the AI state badge, the approval count
 * (the only field allowed to drive a badge, health contract §6) and the
 * privacy mode (always visible as text). Read from the same sources as
 * /health/ready so the header and the menu bar never disagree. The account
 * line and the passkey status come from S1-02's authentication layer.
 */
final readonly class ShellState
{
    public function __construct(
        public AiState $aiState,
        public ?string $aiProvider,
        public int $pendingApprovals,
        public string $privacyMode,
        public ?string $email,
        public PasskeyStatus $passkeys,
    ) {}

    public function aiReady(): bool
    {
        return $this->aiState === AiState::Ready;
    }
}
