<?php

declare(strict_types=1);

namespace App\Browser\ComputerUse;

/**
 * Lifecycle of a `browser_sessions` row (ADR-005 D1 and D2). `Paused` is the locked
 * screen / asleep display case: not failed, waiting for the user to unlock manually.
 */
enum AutomationSessionState: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Completed = 'completed';
    case Expired = 'expired';
    case Aborted = 'aborted';
}
