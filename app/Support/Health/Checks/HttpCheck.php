<?php

declare(strict_types=1);

namespace App\Support\Health\Checks;

use App\Support\Health\CheckOutcome;
use App\Support\Health\HealthCheck;

/**
 * Trivially ok when the handler runs: the request reached the application
 * through the bundled HTTP server and the kernel booted (contract §2).
 */
final class HttpCheck implements HealthCheck
{
    public function name(): string
    {
        return 'http';
    }

    public function dependsOn(): array
    {
        return [];
    }

    public function run(): CheckOutcome
    {
        return CheckOutcome::ok('loopback HTTP responding');
    }
}
