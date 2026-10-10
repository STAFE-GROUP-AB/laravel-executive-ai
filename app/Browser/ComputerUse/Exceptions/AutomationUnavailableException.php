<?php

declare(strict_types=1);

namespace App\Browser\ComputerUse\Exceptions;

use RuntimeException;

/**
 * The helper cannot observe or act for an environmental reason, as opposed to a
 * policy refusal (AutomationDeniedException). Blueprint §6: this must never block
 * install or CRM/AI chat; callers degrade the feature, not the app.
 */
final class AutomationUnavailableException extends RuntimeException
{
    public function __construct(
        public readonly AutomationUnavailableReason $reason,
        string $message,
    ) {
        parent::__construct($message);
    }
}
