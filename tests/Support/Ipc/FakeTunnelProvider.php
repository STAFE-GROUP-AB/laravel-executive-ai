<?php

declare(strict_types=1);

namespace Tests\Support\Ipc;

use App\Support\Cancellation\CancellationToken;
use App\Support\Install\Tunnel\TunnelConfig;
use App\Support\Install\Tunnel\TunnelResult;
use App\Support\Install\Tunnel\TunnelState;
use App\Support\Install\Tunnel\TunnelStatus;
use App\Support\Install\Tunnel\TunnelVerificationStep;
use App\Support\Install\TunnelProvider;
use DateTimeImmutable;

/**
 * A scripted TunnelProvider for the §6.7 operations: reports the given
 * state, records configure/disable calls, and never touches a CLI.
 */
final class FakeTunnelProvider implements TunnelProvider
{
    /** @var list<TunnelConfig> */
    public array $configured = [];

    public int $disabled = 0;

    /**
     * @param  list<string>  $leftoverHandlers
     * @param  list<string>  $driftDetails
     */
    public function __construct(
        private TunnelState $state = TunnelState::Ready,
        private readonly ?string $dnsName = 'my-mac.example-tailnet.ts.net',
        private readonly ?string $clientVersion = '1.98.9',
        private readonly array $leftoverHandlers = [],
        private readonly array $driftDetails = [],
    ) {}

    public function inspect(): TunnelStatus
    {
        return new TunnelStatus(
            state: $this->state,
            inspectedAt: new DateTimeImmutable,
            clientVersion: $this->clientVersion,
            dnsName: $this->dnsName,
            origin: $this->state === TunnelState::Active && $this->dnsName !== null ? "https://{$this->dnsName}" : null,
            loopbackPort: 8787,
            driftDetails: $this->driftDetails,
        );
    }

    public function configure(TunnelConfig $config, CancellationToken $cancellation): TunnelResult
    {
        $this->configured[] = $config;
        $this->state = TunnelState::Active;

        return new TunnelResult(true, $this->inspect(), [new TunnelVerificationStep('self_request', true, '200 with X-Lxai-Origin')], [], null);
    }

    public function disable(): TunnelResult
    {
        $this->disabled++;
        $this->state = TunnelState::Ready;

        return new TunnelResult(true, $this->inspect(), [], $this->leftoverHandlers, null);
    }
}
