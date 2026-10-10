<?php

declare(strict_types=1);

namespace App\Browser\ComputerUse;

/**
 * How an authorized action ended. `Denied` is the helper's own second check
 * refusing (allowlist, denylist, secure field, hash mismatch); `TimedOut` is the
 * 10 s per-action wall clock (ADR-005 D1.8); `Cancelled` is a panic stop or token.
 */
enum ActionOutcome: string
{
    case Executed = 'executed';
    case Denied = 'denied';
    case Failed = 'failed';
    case TimedOut = 'timed_out';
    case Cancelled = 'cancelled';
}
