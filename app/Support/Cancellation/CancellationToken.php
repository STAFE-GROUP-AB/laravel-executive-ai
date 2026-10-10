<?php

declare(strict_types=1);

namespace App\Support\Cancellation;

/**
 * Cooperative cancellation handle passed to every long-running contract method
 * (blueprint §3: "cancellation" is part of the implementation contract; ADR-004 D7
 * checks it between agent steps and per stream chunk; ADR-005 D1.9 panic stop).
 *
 * Implementations poll `isCancelled()` at safe points and call `throwIfCancelled()`
 * where unwinding is the right reaction. A token is observed, never flipped, by the
 * callee: cancellation is requested through a CancellationSource owned by the caller.
 */
interface CancellationToken
{
    public function isCancelled(): bool;

    /**
     * Human-readable reason recorded when cancellation was requested, null while live.
     */
    public function reason(): ?string;

    /**
     * @throws OperationCancelledException when cancellation has been requested
     */
    public function throwIfCancelled(): void;
}
