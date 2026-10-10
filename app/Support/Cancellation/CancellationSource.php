<?php

declare(strict_types=1);

namespace App\Support\Cancellation;

/**
 * Caller-owned handle that requests cancellation. Hand the source's token to the
 * callee; keep the source. The menu bar "Pause AI actions" switch (ADR-004 D7) and
 * the automation panic stop (ADR-005 D1.9) each flip one of these.
 */
final class CancellationSource implements CancellationToken
{
    private bool $cancelled = false;

    private ?string $reason = null;

    public function cancel(?string $reason = null): void
    {
        if ($this->cancelled) {
            return;
        }

        $this->cancelled = true;
        $this->reason = $reason;
    }

    public function token(): CancellationToken
    {
        return $this;
    }

    public function isCancelled(): bool
    {
        return $this->cancelled;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }

    public function throwIfCancelled(): void
    {
        if (! $this->cancelled) {
            return;
        }

        throw new OperationCancelledException($this->reason);
    }
}
