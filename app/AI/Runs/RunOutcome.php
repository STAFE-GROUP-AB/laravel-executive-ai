<?php

declare(strict_types=1);

namespace App\AI\Runs;

/**
 * How one `ai_runs` row ended. `Cancelled` is required by ADR-004 D2 row 6 and D7
 * (`CancellationToken` between steps, the menu bar's "Pause AI actions"), and is a
 * first-class outcome rather than a flavour of failure. `Refused` is the fail-closed
 * path: a capability the model lacks, an exhausted budget, an open circuit or a
 * `PolicyGate` denial — nothing ran and nothing was degraded (ADR-004 D3).
 */
enum RunOutcome: string
{
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Refused = 'refused';
    case Cancelled = 'cancelled';

    /**
     * Whether the row currently occupies the dedupe slot for its key and window.
     * The `ai_runs.dedupe_marker` generated column encodes exactly this, so the
     * unique index lets any number of failed, refused or cancelled runs coexist
     * with at most one running-or-succeeded run per key (ADR-004 D7 dedupe).
     */
    public function holdsDedupeSlot(): bool
    {
        return match ($this) {
            self::Running, self::Succeeded => true,
            self::Failed, self::Refused, self::Cancelled => false,
        };
    }

    public function isFinished(): bool
    {
        return $this !== self::Running;
    }
}
