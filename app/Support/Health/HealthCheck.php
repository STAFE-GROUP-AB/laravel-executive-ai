<?php

declare(strict_types=1);

namespace App\Support\Health;

/**
 * One §12.2 subcheck of the health contract (docs/architecture/health-contract.md §2).
 * Implementations are O(1): an indexed read or a cached heartbeat. They never
 * generate a brief, call a model, call a provider, migrate or spawn a process.
 */
interface HealthCheck
{
    public function name(): string;

    /**
     * Names of checks that must be `ok` before this one is evaluated; when one
     * of them failed this check is reported as `skip` (contract §2).
     *
     * @return array<int, string>
     */
    public function dependsOn(): array;

    public function run(): CheckOutcome;
}
