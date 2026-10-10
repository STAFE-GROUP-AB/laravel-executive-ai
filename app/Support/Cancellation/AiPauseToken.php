<?php

declare(strict_types=1);

namespace App\Support\Cancellation;

/**
 * The CancellationToken view of the global pause switch: observed by model
 * runs, never flipped by them (the switch is flipped through the IPC
 * operation or the PWA, as the owner).
 */
final readonly class AiPauseToken implements CancellationToken
{
    public function __construct(private AiPauseSwitch $switch) {}

    public function isCancelled(): bool
    {
        return $this->switch->isPaused();
    }

    public function reason(): ?string
    {
        $status = $this->switch->status();

        return $status->paused ? ($status->reason ?? 'AI actions are paused.') : null;
    }

    public function throwIfCancelled(): void
    {
        if (! $this->isCancelled()) {
            return;
        }

        throw new OperationCancelledException($this->reason());
    }
}
