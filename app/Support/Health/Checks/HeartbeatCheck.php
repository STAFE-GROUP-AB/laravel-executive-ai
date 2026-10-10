<?php

declare(strict_types=1);

namespace App\Support\Health\Checks;

use App\Support\Health\CheckOutcome;
use App\Support\Health\HealthCheck;
use App\Support\Health\Heartbeats;

/**
 * Shared shape of the three heartbeat-based checks: ok while the last
 * heartbeat is younger than the configured threshold, with `age_seconds`
 * and `threshold_seconds` always reported (contract §2 `Check`).
 */
abstract class HeartbeatCheck implements HealthCheck
{
    public function __construct(protected readonly Heartbeats $heartbeats) {}

    abstract protected function heartbeat(): string;

    abstract protected function okDetail(int $ageSeconds): string;

    abstract protected function staleDetail(int $ageSeconds, int $threshold): string;

    abstract protected function missingDetail(): string;

    protected function threshold(): int
    {
        return (int) config("health.thresholds.{$this->name()}");
    }

    public function run(): CheckOutcome
    {
        $threshold = $this->threshold();
        $age = $this->heartbeats->ageSeconds($this->heartbeat());

        if ($age === null) {
            return CheckOutcome::fail($this->missingDetail(), thresholdSeconds: $threshold);
        }

        if ($age > $threshold) {
            return CheckOutcome::fail($this->staleDetail($age, $threshold), $age, $threshold);
        }

        return CheckOutcome::ok($this->okDetail($age), $age, $threshold);
    }
}
