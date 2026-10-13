<?php

declare(strict_types=1);

namespace App\AI\Tools;

/**
 * How one `tool_runs` row ended. `Denied` is the `PolicyGate` (or the automation
 * helper's own second check) refusing — the tool produced a result the model can
 * read and nothing executed (ADR-004 D2 row 3). Mirrors
 * `App\Browser\ComputerUse\ActionOutcome` so a computer-use row and its action
 * result never disagree.
 */
enum ToolRunOutcome: string
{
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Denied = 'denied';
    case TimedOut = 'timed_out';
    case Cancelled = 'cancelled';

    public function isFinished(): bool
    {
        return $this !== self::Running;
    }
}
