<?php

declare(strict_types=1);

namespace App\Support\Install\Tunnel;

/**
 * One check of the ADR-006 D2 verification sequence (`serve status --json` parses
 * to `Active`; self-request over the tailnet returns 200 with the origin header).
 */
final readonly class TunnelVerificationStep
{
    public function __construct(
        public string $name,
        public bool $passed,
        public ?string $detail,
    ) {}
}
