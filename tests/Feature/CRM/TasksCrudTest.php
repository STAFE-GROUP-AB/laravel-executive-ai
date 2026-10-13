<?php

declare(strict_types=1);

use App\Briefs\Priority;
use App\CRM\Tasks\TaskStatus;
use App\Livewire\Crm\Tasks;
use App\Models\Approvals\ActionApproval;
use App\Models\CRM\Task;
use App\Security\Retention\RetentionRules;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\Support\CrmRecords;
use Tests\Support\PwaFixtures;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(DatabaseTransactions::class);

/*
 * S2-09 (#91): `/crm/tasks` — todos, including the follow-ups §3.2 asks the
 * assistant to remember.
 *
 * The §4.2 case is the one that would be easy to get wrong and hard to notice:
 * **a task with no stated deadline must keep none.** A form that defaulted
 * `due_at` to "a week from today" would make every task look like a commitment
 * somebody made, which is the invented deadline §4.2 forbids. So there is a
 * test for the absence of a value.
 */

it('requires a signed-in user', function (): void {
    get('/crm/tasks')->assertRedirect(route('login'));
});

it('renders the page with its empty state', function (): void {
    actingAs(PwaFixtures::owner())->get('/crm/tasks')->assertOk()
        ->assertSee(__('crm.tasks.title'))
        ->assertSee(__('crm.tasks.empty'));
});

it('creates a task with no deadline when none was stated', function (): void {
    $user = PwaFixtures::owner();

    Livewire::actingAs($user)->test(Tasks::class)
        ->call('startCreate')
        // The deadline field is empty by default and is left alone here.
        ->assertSet('form.due_at', '')
        ->set('form.title', 'Ring Elin om offerten')
        ->call('save')
        ->assertHasNoErrors();

    $task = Task::query()->sole();

    expect($task->due_at)->toBeNull('§4.2: a deadline nobody stated must not be invented')
        ->and($task->status)->toBe(TaskStatus::Todo)
        ->and($task->priority)->toBe(Priority::Normal)
        ->and($task->customer_id)->toBeNull()
        ->and($task->project_id)->toBeNull()
        ->and($task->contact_id)->toBeNull()
        ->and($task->provenance['actor'])->toBe("user:{$user->id}")
        ->and(RetentionRules::resolve((string) $task->retention_policy)->governs('tasks'))->toBeTrue();
});

it('keeps a deadline that was stated, and the brief priority vocabulary', function (): void {
    $user = PwaFixtures::owner();

    Livewire::actingAs($user)->test(Tasks::class)
        ->call('startCreate')
        ->set('form.title', 'Skicka avtalsutkast')
        ->set('form.due_at', '2026-10-17T09:30')
        ->set('form.priority', Priority::Critical->value)
        ->call('save')
        ->assertHasNoErrors();

    $task = Task::query()->sole();

    expect($task->due_at?->format('Y-m-d H:i'))->toBe('2026-10-17 09:30')
        // Shared with the brief items so a task lifts into a brief unchanged.
        ->and($task->priority)->toBe(Priority::Critical);
});

it('links a task to a customer, a project and a contact', function (): void {
    $user = PwaFixtures::owner();
    $organization = PwaFixtures::organizationOf($user);
    $customer = CrmRecords::customer($organization, 'Nordlund Logistik');
    $project = CrmRecords::project($customer, 'Lagerlokal Mölndal');
    $contact = CrmRecords::contact($organization, 'Elin Sjöström');

    Livewire::actingAs($user)->test(Tasks::class)
        ->call('startCreate')
        ->set('form.title', 'Boka platsbesök')
        ->set('form.customer_id', (string) $customer->getKey())
        ->set('form.project_id', (string) $project->getKey())
        ->set('form.contact_id', (string) $contact->getKey())
        ->call('save')
        ->assertHasNoErrors();

    $task = Task::query()->sole();

    expect($task->customer_id)->toBe((string) $customer->getKey())
        ->and($task->project_id)->toBe((string) $project->getKey())
        ->and($task->contact_id)->toBe((string) $contact->getKey());
});

it('refuses a task with no title', function (): void {
    Livewire::actingAs(PwaFixtures::owner())->test(Tasks::class)
        ->call('startCreate')
        ->set('form.title', '')
        ->call('save')
        ->assertHasErrors(['form.title' => 'required']);

    expect(Task::query()->count())->toBe(0);
});

it('updates a task and clears a deadline back to none', function (): void {
    $user = PwaFixtures::owner();
    $task = CrmRecords::task(PwaFixtures::organizationOf($user), 'Ring Elin', [
        'due_at' => CarbonImmutable::parse('2026-10-17 09:30'),
    ]);

    Livewire::actingAs($user)->test(Tasks::class)
        ->call('startEdit', (string) $task->getKey())
        ->assertSet('form.due_at', '2026-10-17T09:30')
        ->set('form.due_at', '')
        ->set('form.status', TaskStatus::Done->value)
        ->call('save')
        ->assertHasNoErrors();

    $task->refresh();

    expect($task->due_at)->toBeNull()
        ->and($task->status)->toBe(TaskStatus::Done);
});

it('shows "no deadline" in words rather than an empty cell', function (): void {
    $user = PwaFixtures::owner();
    CrmRecords::task(PwaFixtures::organizationOf($user), 'Ring Elin');

    actingAs($user)->get('/crm/tasks')->assertOk()->assertSee(__('crm.tasks.no_due_date'));
});

it('filters by status', function (): void {
    $user = PwaFixtures::owner();
    $organization = PwaFixtures::organizationOf($user);
    CrmRecords::task($organization, 'Ring Elin');
    CrmRecords::task($organization, 'Skicka avtal', ['status' => TaskStatus::Done]);

    Livewire::actingAs($user)->test(Tasks::class)
        ->set('status', TaskStatus::Done->value)
        ->assertSee('Skicka avtal')
        ->assertDontSee('Ring Elin');
});

it('deletes a task only through a proposal and an approval', function (): void {
    $user = PwaFixtures::owner();
    $task = CrmRecords::task(PwaFixtures::organizationOf($user), 'Ring Elin');

    Livewire::actingAs($user)->test(Tasks::class)
        ->call('proposeDeletion', (string) $task->getKey());

    expect($task->fresh()?->trashed())->toBeFalse();

    Livewire::actingAs($user)->test(Tasks::class)
        ->call('approveDeletion', (string) $task->getKey());

    expect(Task::withTrashed()->whereKey($task->getKey())->sole()->trashed())->toBeTrue()
        ->and(ActionApproval::query()->count())->toBe(1);
});

it('shows deleted tasks only when asked, and restores one', function (): void {
    $user = PwaFixtures::owner();
    $task = CrmRecords::task(PwaFixtures::organizationOf($user), 'Ring Elin');

    Livewire::actingAs($user)->test(Tasks::class)
        ->call('proposeDeletion', (string) $task->getKey())
        ->call('approveDeletion', (string) $task->getKey())
        ->assertDontSee('Ring Elin')
        ->set('showDeleted', true)
        ->assertSee('Ring Elin')
        ->call('restoreRecord', (string) $task->getKey());

    expect(Task::query()->whereKey($task->getKey())->exists())->toBeTrue();
});
