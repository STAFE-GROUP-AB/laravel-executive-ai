<?php

declare(strict_types=1);

namespace App\Support\Install\Tunnel;

use InvalidArgumentException;

/**
 * Blueprint §3 `configure(TunnelConfig)`. The only thing the app may configure is a
 * single HTTPS origin proxied to `http://127.0.0.1:<loopbackPort>` on a private
 * tailnet (ADR-006 D1, D2). There is no field for a public mode: Funnel, LAN
 * exposure and alternative adapters are not expressible here by design (D6).
 *
 * `replaceDriftedConfiguration` is the user's explicit "Replace" choice from the
 * `Drifted` screen; `confirmationId` references the stored D1.3 confirmation so an
 * implementation can refuse a configure call that no screen produced.
 */
final readonly class TunnelConfig
{
    public function __construct(
        public int $loopbackPort,
        public string $confirmationId,
        public bool $replaceDriftedConfiguration = false,
    ) {
        if ($this->loopbackPort < 1024 || $this->loopbackPort > 65535) {
            throw new InvalidArgumentException('The loopback port must be an unprivileged port (1024–65535).');
        }

        if (trim($this->confirmationId) === '') {
            throw new InvalidArgumentException('Remote access cannot be configured without a recorded user confirmation.');
        }
    }
}
