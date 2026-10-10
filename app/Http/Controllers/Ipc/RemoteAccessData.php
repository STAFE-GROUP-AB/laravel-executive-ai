<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ipc;

use App\Support\Install\Tunnel\TunnelState;
use App\Support\Install\Tunnel\TunnelStatus;
use Carbon\CarbonImmutable;

/**
 * Maps a `TunnelStatus` to the §6.7 `data` shape. `state` is ADR-006 D2's
 * PascalCase name; `origin` is the HTTPS origin the PWA is or would be
 * bound to; `detail` is a fixed sentence per state (never CLI output, which
 * may carry the tailnet name).
 */
final class RemoteAccessData
{
    /** @return array{state: string, origin: ?string, tailscale: array{variant: ?string, version: ?string}, detail: string, checked_at: string} */
    public static function status(TunnelStatus $status): array
    {
        return [
            'state' => $status->state->name,
            'origin' => self::origin($status),
            'tailscale' => [
                'variant' => null,
                'version' => $status->clientVersion,
            ],
            'detail' => self::detail($status),
            'checked_at' => CarbonImmutable::instance($status->inspectedAt)->setTimezone((string) config('app.timezone', 'UTC'))->format('Y-m-d\TH:i:sP'),
        ];
    }

    public static function origin(TunnelStatus $status): ?string
    {
        if ($status->origin !== null) {
            return $status->origin;
        }

        if ($status->dnsName === null || $status->dnsName === '') {
            return null;
        }

        return 'https://'.rtrim($status->dnsName, '.');
    }

    private static function detail(TunnelStatus $status): string
    {
        $foreign = count($status->driftDetails);

        return match ($status->state) {
            TunnelState::NotInstalled => 'Tailscale is not installed or not available in this build',
            TunnelState::NotLoggedIn => 'Tailscale is installed but not logged in',
            TunnelState::HttpsNotEnabled => 'Tailscale running; HTTPS certificates are not enabled for this tailnet',
            TunnelState::Ready => 'Tailscale running; HTTPS certificates enabled; no serve config',
            TunnelState::Active => 'Tailscale Serve is proxying the loopback core on the private tailnet',
            TunnelState::Drifted => "Serve configuration differs from the expected single-origin proxy ({$foreign} foreign handler(s))",
            TunnelState::FunnelDetected => 'Funnel (public exposure) detected; the app will not configure remote access until it is removed',
        };
    }
}
