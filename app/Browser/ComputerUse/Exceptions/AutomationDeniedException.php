<?php

declare(strict_types=1);

namespace App\Browser\ComputerUse\Exceptions;

use App\Approvals\Policies\PolicyDeniedException;

/**
 * The PHP-side AutomationPolicy refused before anything reached the helper: target
 * not on the session allowlist or on the hard denylist, session not live, approval
 * expired or nonce reused, proposal hash mismatch (ADR-005 D1.5–D1.6).
 */
final class AutomationDeniedException extends PolicyDeniedException
{
    public function __construct(
        string $message,
        public readonly string $sessionId,
    ) {
        parent::__construct($message, 'automation.policy');
    }
}
