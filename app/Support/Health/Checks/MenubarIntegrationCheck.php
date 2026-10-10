<?php

declare(strict_types=1);

namespace App\Support\Health\Checks;

use App\Support\Health\Heartbeats;

/**
 * The SwiftUI app sent an authenticated IPC heartbeat within the threshold
 * (default 120 s). The IPC server (S1-08) records it via
 * Heartbeats::record(Heartbeats::MENUBAR, ['client_version' => ...]).
 */
final class MenubarIntegrationCheck extends HeartbeatCheck
{
    public function name(): string
    {
        return 'menubar_integration';
    }

    public function dependsOn(): array
    {
        return [];
    }

    protected function heartbeat(): string
    {
        return Heartbeats::MENUBAR;
    }

    protected function okDetail(int $ageSeconds): string
    {
        return "last IPC heartbeat {$ageSeconds}s ago";
    }

    protected function staleDetail(int $ageSeconds, int $threshold): string
    {
        return "last IPC heartbeat {$ageSeconds}s ago (threshold {$threshold}s); menu bar app not running or not paired";
    }

    protected function missingDetail(): string
    {
        return 'no IPC heartbeat recorded; menu bar app not running or not paired';
    }
}
