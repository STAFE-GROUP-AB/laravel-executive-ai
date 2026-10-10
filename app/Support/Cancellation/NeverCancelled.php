<?php

declare(strict_types=1);

namespace App\Support\Cancellation;

/**
 * Token for call sites that have no cancellation path (one-shot CLI commands,
 * fixtures). Using it is a visible choice at the call site, not a default.
 */
final readonly class NeverCancelled implements CancellationToken
{
    public function isCancelled(): bool
    {
        return false;
    }

    public function reason(): ?string
    {
        return null;
    }

    public function throwIfCancelled(): void
    {
        // Never cancelled by construction.
    }
}
