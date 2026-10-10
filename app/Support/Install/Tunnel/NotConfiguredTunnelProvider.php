<?php

declare(strict_types=1);

namespace App\Support\Install\Tunnel;

use App\Support\Cancellation\CancellationToken;
use App\Support\Install\TunnelProvider;
use DateTimeImmutable;

/**
 * The Sprint 1 binding until `TailscaleServeTunnelProvider` lands (ADR-006,
 * E4-1): reports `NotInstalled`, refuses to configure, and disables as a
 * no-op. Lets the IPC remote-access operations (§6.7) run end to end with
 * the real envelopes while never producing any exposure.
 */
final class NotConfiguredTunnelProvider implements TunnelProvider
{
    public function inspect(): TunnelStatus
    {
        return new TunnelStatus(
            state: TunnelState::NotInstalled,
            inspectedAt: new DateTimeImmutable,
            clientVersion: null,
            dnsName: null,
            origin: null,
            loopbackPort: (int) config('ipc.port'),
        );
    }

    public function configure(TunnelConfig $config, CancellationToken $cancellation): TunnelResult
    {
        throw new TunnelException(TunnelFailureReason::NotReady, 'Remote access is not available in this build.', $this->inspect());
    }

    public function disable(): TunnelResult
    {
        return new TunnelResult(true, $this->inspect(), [], [], 'Nothing to disable: remote access is not available in this build.');
    }
}
