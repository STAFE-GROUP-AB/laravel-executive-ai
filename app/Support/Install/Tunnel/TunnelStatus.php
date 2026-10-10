<?php

declare(strict_types=1);

namespace App\Support\Install\Tunnel;

use DateTimeImmutable;

/**
 * Blueprint §3 `inspect(): TunnelStatus`. A read-only snapshot built from the three
 * read-only CLI calls in ADR-006 D2 (`version`, `status --json`, `serve status
 * --json`). `origin` is the HTTPS origin the PWA will be bound to and is set only in
 * `Active`; `driftDetails` lists the foreign handlers found in `Drifted` and
 * `FunnelDetected` so the UI can show the diff.
 */
final readonly class TunnelStatus
{
    /**
     * @param  list<string>  $driftDetails
     */
    public function __construct(
        public TunnelState $state,
        public DateTimeImmutable $inspectedAt,
        public ?string $clientVersion,
        public ?string $dnsName,
        public ?string $origin,
        public ?int $loopbackPort,
        public array $driftDetails = [],
    ) {}

    public function isActive(): bool
    {
        return $this->state === TunnelState::Active;
    }
}
