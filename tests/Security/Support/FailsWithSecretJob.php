<?php

declare(strict_types=1);

namespace Tests\Security\Support;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Test double for QueuePayloadTest: carries only a reference (the ADR-003
 * SecretRef pattern), fetches the value at run time and fails with it in
 * the exception message, the way a careless adapter would.
 */
final class FailsWithSecretJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public string $secretRef) {}

    public function handle(): void
    {
        $secret = (string) Cache::get("test-secret:{$this->secretRef}");

        throw new RuntimeException("provider rejected credentials: {$secret}");
    }
}
