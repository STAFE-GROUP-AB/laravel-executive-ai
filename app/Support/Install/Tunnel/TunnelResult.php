<?php

declare(strict_types=1);

namespace App\Support\Install\Tunnel;

/**
 * Blueprint §3 `configure(...): TunnelResult`, also returned by `disable()`. Carries
 * the status observed *after* the change, the verification steps that were run, and
 * any handlers the app did not create and therefore left alone (ADR-006 D2
 * "Disable"). A failed `configure()` has already been rolled back by `disable()`
 * before this is returned.
 */
final readonly class TunnelResult
{
    /**
     * @param  list<TunnelVerificationStep>  $verification
     * @param  list<string>  $leftoverHandlers
     */
    public function __construct(
        public bool $succeeded,
        public TunnelStatus $status,
        public array $verification,
        public array $leftoverHandlers,
        public ?string $message,
    ) {}
}
