<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Security\Authorization\Otp\OtpCode;
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

/*
 * #124: `brief_placeholder` gates `ready` (health contract §2, blueprint §12.2 "local brief
 * placeholder"), and nothing produced it. `lea:brief:placeholder` existed and was idempotent, but
 * its only callers were tests and documentation, so on a real first install the check could never
 * pass and HEALTH_CHECK could only time out.
 *
 * The check is right — the blueprint requires a placeholder for core-ready — so the producer was
 * what was missing. It belongs on the scheduler rather than in the installer because it needs an
 * organization, which exists only after CREATE_OWNER, and because the scheduler is already what
 * makes the other ageing checks (`auth_login`, `scheduler_selftest`, `queue_worker`) pass. One
 * tick therefore satisfies all of them, so this adds no latency to the install. It is also
 * self-healing: a data-preserving reinstall, or a database restored from a backup without a
 * brief, gets one within the minute.
 *
 * `when()` keeps it quiet before the owner exists — "no organization yet" is not a failure — and
 * the command itself is the idempotence guarantee: an organization that already has a brief is
 * left alone.
 */
Schedule::command('lea:brief:placeholder')
    ->everyMinute()
    ->withoutOverlapping(2)
    ->when(fn (): bool => Organization::query()->exists())
    ->description('Ensure the install-time placeholder brief exists once an owner does (§12.2)');

Schedule::command('model:prune', ['--model' => [OtpCode::class]])
    ->daily()
    ->description('Delete one-time login codes that expired more than a day ago (S1-02)');
