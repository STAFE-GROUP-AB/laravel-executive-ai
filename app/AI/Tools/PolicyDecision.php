<?php

declare(strict_types=1);

namespace App\AI\Tools;

/**
 * What the server-side `PolicyGate` decided before the tool ran (blueprint §4: the
 * LLM proposes, the gate decides). Every `tool_runs` row carries one — a tool that
 * was never put to the gate is not recordable, because the column is not nullable.
 */
enum PolicyDecision: string
{
    case Allowed = 'allowed';
    case Denied = 'denied';

    public function permitsExecution(): bool
    {
        return $this === self::Allowed;
    }
}
