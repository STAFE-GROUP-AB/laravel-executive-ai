<?php

declare(strict_types=1);

use App\Models\Brief;
use App\Models\Organization;
use App\Support\Health\HealthService;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\OwnerAccount;
use Tests\Support\PwaFixtures;

use function Pest\Laravel\artisan;

/*
 * #124: `brief_placeholder` gates `ready` (health contract §2; blueprint §12.2 "local brief
 * placeholder") and **nothing produced it**. `lea:brief:placeholder` existed and was idempotent,
 * but its only callers were tests and three documentation files, so on a real first install the
 * check could never pass and HEALTH_CHECK could only ever time out after its whole budget.
 *
 * The check is not wrong — the blueprint requires a placeholder for core-ready — so the producer
 * was what was missing, and it is now a scheduled, idempotent, self-healing command. These tests
 * pin that it is scheduled, that it does nothing before an owner exists, that it makes the check
 * pass once one does, and that it never touches a brief that is already there.
 */

uses(DatabaseTransactions::class);

beforeEach(function (): void {
    OwnerAccount::clear();
    Brief::query()->forceDelete();
    Organization::query()->forceDelete();
    app(HealthService::class)->forgetCached();
});

/**
 * `getSummaryForDisplay()` is the description when one is set (as it is for every entry in
 * `routes/console.php`), so the description is what identifies the entry.
 */
function placeholderSchedule(): ?Event
{
    return collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains($event->getSummaryForDisplay(), 'placeholder brief'));
}

it('schedules the placeholder producer every minute', function (): void {
    $event = placeholderSchedule();

    expect($event)->not->toBeNull('nothing would ever create the placeholder brief')
        ->and($event?->expression)->toBe('* * * * *')
        ->and($event?->command)->toContain('lea:brief:placeholder');
});

it('does not run before an owner exists, because there is no organization to own the brief', function (): void {
    $event = placeholderSchedule();

    expect(Organization::query()->exists())->toBeFalse()
        ->and($event?->filtersPass(app()))->toBeFalse('a fresh pre-owner install must not log a failure every minute');
});

it('runs once an owner exists and makes the brief_placeholder check pass', function (): void {
    $owner = OwnerAccount::create();
    $organization = PwaFixtures::organizationOf($owner);
    $event = placeholderSchedule();

    expect($event?->filtersPass(app()))->toBeTrue()
        ->and(Brief::query()->where('organization_id', $organization->id)->exists())->toBeFalse();

    $byName = array_column(app(HealthService::class)->ready()->toArray()['checks'], null, 'name');
    expect($byName['brief_placeholder']['status'])->toBe('fail');

    artisan('lea:brief:placeholder')->assertSuccessful();
    app(HealthService::class)->forgetCached();

    $byName = array_column(app(HealthService::class)->ready()->toArray()['checks'], null, 'name');

    expect(Brief::query()->where('organization_id', $organization->id)->exists())->toBeTrue()
        ->and($byName['brief_placeholder']['status'])->toBe('ok');
});

it('leaves an existing brief alone, so a reinstall never overwrites real data', function (): void {
    $owner = OwnerAccount::create();
    $organization = PwaFixtures::organizationOf($owner);

    artisan('lea:brief:placeholder')->assertSuccessful();

    $first = Brief::query()->where('organization_id', $organization->id)->firstOrFail();

    artisan('lea:brief:placeholder')->expectsOutputToContain('nothing to do')->assertSuccessful();

    $briefs = Brief::query()->where('organization_id', $organization->id)->get();

    expect($briefs)->toHaveCount(1)
        ->and($briefs->first()?->id)->toBe($first->id)
        ->and($briefs->first()?->document_id)->toBe($first->document_id);
});
