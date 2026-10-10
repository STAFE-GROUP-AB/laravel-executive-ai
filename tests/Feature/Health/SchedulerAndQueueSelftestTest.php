<?php

declare(strict_types=1);

use App\Support\Health\Heartbeats;
use App\Support\Health\Jobs\QueueSelftestJob;
use App\Support\Health\QueueWorkerHeartbeat;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Queue;
use Tests\Support\CoreReadyState;

use function Pest\Laravel\artisan;

uses(DatabaseTransactions::class);

it('schedules health:selftest and the queue self-test job every minute', function (): void {
    $events = collect(app(Schedule::class)->events());
    $summaries = $events->map(fn (Event $event): string => $event->getSummaryForDisplay());

    expect($summaries->first(fn (string $summary): bool => str_contains($summary, 'Scheduler heartbeat')))->not->toBeNull()
        ->and($summaries->first(fn (string $summary): bool => str_contains($summary, 'queue self-test')))->not->toBeNull()
        ->and($events->pluck('expression')->unique()->all())->toBe(['* * * * *']);
});

it('records the scheduler heartbeat and login self-test from health:selftest', function (): void {
    CoreReadyState::create();
    $heartbeats = app(Heartbeats::class);
    $heartbeats->forget(Heartbeats::SCHEDULER);
    $heartbeats->forget(Heartbeats::LOGIN_SELFTEST);

    artisan('health:selftest')->expectsOutputToContain('login self-test passed')->assertSuccessful();

    expect($heartbeats->ageSeconds(Heartbeats::SCHEDULER))->toBe(0)
        ->and($heartbeats->read(Heartbeats::LOGIN_SELFTEST)['passed'])->toBeTrue();
});

it('records a failed login self-test when no owner exists', function (): void {
    artisan('health:selftest')->expectsOutputToContain('did not pass')->assertSuccessful();

    expect(app(Heartbeats::class)->read(Heartbeats::LOGIN_SELFTEST)['passed'])->toBeFalse();
});

it('records the queue self-test heartbeat when the worker processes the job', function (): void {
    $heartbeats = app(Heartbeats::class);
    $heartbeats->forget(Heartbeats::QUEUE_SELFTEST);

    (new QueueSelftestJob)->handle($heartbeats);

    expect($heartbeats->ageSeconds(Heartbeats::QUEUE_SELFTEST))->toBe(0);
});

it('dispatches the queue self-test job on the database queue', function (): void {
    Queue::fake();

    QueueSelftestJob::dispatch();

    Queue::assertPushed(QueueSelftestJob::class);
    expect(config('queue.default'))->toBe('database');
});

it('writes the worker heartbeat from the queue loop and throttles repeats', function (): void {
    $heartbeats = app(Heartbeats::class);
    $heartbeats->forget(Heartbeats::QUEUE_WORKER);
    QueueWorkerHeartbeat::resetThrottle();

    $listener = app(QueueWorkerHeartbeat::class);
    $listener->handle(new Looping('database', 'default'));

    $first = $heartbeats->read(Heartbeats::QUEUE_WORKER);
    $heartbeats->forget(Heartbeats::QUEUE_WORKER);
    $listener->handle(new Looping('database', 'default'));

    expect($first['queue'])->toBe('default')
        ->and($heartbeats->read(Heartbeats::QUEUE_WORKER))->toBeNull();

    QueueWorkerHeartbeat::resetThrottle();
});
