<?php

declare(strict_types=1);

namespace App\Support\Install;

use App\Support\Cancellation\CancellationToken;
use App\Support\Cancellation\OperationCancelledException;
use App\Support\Install\Tunnel\TunnelConfig;
use App\Support\Install\Tunnel\TunnelException;
use App\Support\Install\Tunnel\TunnelResult;
use App\Support\Install\Tunnel\TunnelStatus;

/**
 * Blueprint §3, fourth contract: `TunnelProvider` with `inspect()`, `configure()`
 * and `disable()`; blueprint §8; ADR-006. The v1 implementation is
 * `App\Support\Install\Tunnel\TailscaleServeTunnelProvider` (Tailscale Serve on a
 * private tailnet, never Funnel). A second adapter needs its own ADR (ADR-006 D7).
 *
 * Deliberate differences from the §3 sketch (see docs/architecture/module-contracts.md):
 * `configure()` takes a CancellationToken; `disable()` returns a TunnelResult
 * instead of `void` because ADR-006 D2 requires post-disable verification and
 * reporting of handlers the app did not create.
 *
 * Invariants every implementation must hold (ADR-006 D1, D6): nothing is configured
 * without a recorded user confirmation; no call can produce public exposure; the
 * app never signs in, never enables HTTPS certificates on the user's behalf and
 * never touches tailnet policy.
 */
interface TunnelProvider
{
    /**
     * Read-only. Never changes anything, never throws for an absent or signed-out
     * client: those are states, not errors.
     *
     * @throws TunnelException only for an unparseable client response (`CommandFailed`)
     */
    public function inspect(): TunnelStatus;

    /**
     * Apply the single-origin Serve configuration, verify it, and roll back on any
     * verification failure. Allowed only from `Ready`, or from `Drifted` with
     * `replaceDriftedConfiguration` set.
     *
     * @throws TunnelException `NotReady`, `ConfirmationMissing`, `PublicExposureRefused`, `CommandFailed`, `VerificationFailed`
     * @throws OperationCancelledException the token was cancelled; the configuration has been rolled back
     */
    public function configure(TunnelConfig $config, CancellationToken $cancellation): TunnelResult;

    /**
     * Remove the handler the app created and verify it is gone. Handlers the app did
     * not create are left alone and listed in the result. Idempotent: disabling an
     * already-disabled tunnel succeeds.
     *
     * @throws TunnelException `CommandFailed`, `VerificationFailed`
     */
    public function disable(): TunnelResult;
}
