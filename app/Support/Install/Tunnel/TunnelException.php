<?php

declare(strict_types=1);

namespace App\Support\Install\Tunnel;

use RuntimeException;

/**
 * The single failure type of the tunnel contract. `status` is the state observed
 * when the call failed so the UI can go straight to the right screen. The message
 * never includes CLI stdout verbatim (it may contain the tailnet name).
 */
final class TunnelException extends RuntimeException
{
    public function __construct(
        public readonly TunnelFailureReason $reason,
        string $message,
        public readonly ?TunnelStatus $status = null,
    ) {
        parent::__construct($message);
    }
}
