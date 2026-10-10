<?php

declare(strict_types=1);

namespace App\Browser\ComputerUse;

/**
 * Read-only view of the TCC grants the helper holds (ADR-005 D1.4). Displayed to the
 * user; the app never writes to TCC or scripts System Settings.
 */
final readonly class AutomationPermissions
{
    public function __construct(
        public bool $screenRecording,
        public bool $accessibility,
    ) {}
}
