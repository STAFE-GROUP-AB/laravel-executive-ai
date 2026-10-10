<?php

declare(strict_types=1);

use App\Support\Health\Jobs\QueueSelftestJob;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduler (blueprint §12.2, ADR-002 D1)
|--------------------------------------------------------------------------
|
| Two launchd-supervised processes run beside the HTTP server (S1-07):
|   php artisan schedule:work      fires these entries every minute
|   php artisan queue:work database --queue=briefs,email-sync,default,ai --sleep=3 --tries=3
| Neither is optional for core-ready: `scheduler_selftest` and `queue_worker`
| in /health/ready age out within minutes when they stop.
|
*/

Schedule::command('health:selftest')
    ->everyMinute()
    ->withoutOverlapping(2)
    ->description('Scheduler heartbeat and login self-test (§12.2)');

Schedule::job(new QueueSelftestJob)
    ->everyMinute()
    ->description('Dispatch the queue self-test job; the worker records its heartbeat on processing');
