<?php

declare(strict_types=1);

namespace App\Briefs;

/**
 * Brief lifecycle (brief schema §2). Ordered: draft < frozen < queued < sent | failed | skipped.
 * From `frozen` onwards the content envelope is immutable (§5 freeze rule).
 */
enum DeliveryState: string
{
    case Draft = 'draft';
    case Frozen = 'frozen';
    case Queued = 'queued';
    case Sent = 'sent';
    case Failed = 'failed';
    case Skipped = 'skipped';

    public function isFrozen(): bool
    {
        return $this !== self::Draft;
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Sent, self::Failed, self::Skipped], true);
    }

    /**
     * Forward-only transitions: frozen -> queued -> sent | failed | skipped.
     * A draft may move to frozen (or straight to skipped for local preview).
     */
    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Draft => in_array($next, [self::Frozen, self::Skipped], true),
            self::Frozen => in_array($next, [self::Queued, self::Skipped], true),
            self::Queued => $next->isTerminal(),
            default => false,
        };
    }
}
