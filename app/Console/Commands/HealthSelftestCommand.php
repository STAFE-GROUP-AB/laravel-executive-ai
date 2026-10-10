<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Health\Heartbeats;
use App\Support\Health\LoginSelfTest;
use Illuminate\Console\Command;

/**
 * The scheduler self-test (blueprint §12.2, health contract §2
 * `scheduler_selftest`). Scheduled every minute in routes/console.php; it
 * records the scheduler heartbeat and runs the login self-test. If
 * `schedule:work` is not running this stops firing and `ready` drops.
 */
class HealthSelftestCommand extends Command
{
    protected $signature = 'health:selftest';

    protected $description = 'Record the scheduler heartbeat and run the login self-test (§12.2)';

    public function handle(Heartbeats $heartbeats, LoginSelfTest $loginSelfTest): int
    {
        $heartbeats->record(Heartbeats::SCHEDULER);

        $passed = $loginSelfTest->run();

        $this->comment($passed ? 'Scheduler heartbeat recorded; login self-test passed.' : 'Scheduler heartbeat recorded; login self-test did not pass (no owner yet?).');

        return self::SUCCESS;
    }
}
