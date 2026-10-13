<?php

declare(strict_types=1);

use App\CRM\Projects\ProjectStatus;
use App\Livewire\Crm\Projects;
use App\Models\Approvals\ActionApproval;
use App\Models\CRM\Project;
use App\Models\Organization;
use App\Security\Retention\RetentionRules;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\Support\CrmRecords;
use Tests\Support\PwaFixtures;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(DatabaseTransactions::class);

/*
 * S2-09 (#91): `/crm/projects` — projects per customer with status and
 * deadlines (§3.2).
 *
 * Two things this file pins beyond the CRUD: the dates are **calendar dates**
 * and survive as such (a deadline on the 17th is the 17th, not an instant in
 * somebody's timezone), and the customer select is built from a scoped query,
 * so another workspace's customer is neither offered nor writable.
 */

it('requires a signed-in user', function (): void {
    get('/crm/projects')->assertRedirect(route('login'));
});

it('renders the page with its empty state, which says a project needs a customer', function (): void {
    actingAs(PwaFixtures::owner())->get('/crm/projects')->assertOk()
        ->assertSee(__('crm.projects.title'))
        ->assertSee(__('crm.projects.empty'))
        ->assertSee(__('crm.projects.empty_body'));
});

it('creates a project with a calendar deadline', function (): void {
    $user = PwaFixtures::owner();
    $customer = CrmRecords::customer(PwaFixtures::organizationOf($user), 'Nordlund Logistik');

    Livewire::actingAs($user)->test(Projects::class)
        ->call('startCreate')
        ->set('form.name', 'Lagerlokal Mölndal')
        ->set('form.customer_id', (string) $customer->getKey())
        ->set('form.status', ProjectStatus::Planned->value)
        ->set('form.starts_on', '2026-10-01')
        ->set('form.due_on', '2026-10-17')
        ->set('form.description', 'Ombyggnad av lastkaj.')
        ->call('save')
        ->assertHasNoErrors();

    $project = Project::query()->sole();

    expect($project->customer_id)->toBe((string) $customer->getKey())
        ->and($project->status)->toBe(ProjectStatus::Planned)
        // A date, not an instant: the same calendar day wherever it is read.
        ->and($project->due_on?->toDateString())->toBe('2026-10-17')
        ->and($project->starts_on?->toDateString())->toBe('2026-10-01')
        ->and($project->provenance['actor'])->toBe("user:{$user->id}")
        ->and(RetentionRules::resolve((string) $project->retention_policy)->governs('projects'))->toBeTrue();
});

it('requires a customer', function (): void {
    $user = PwaFixtures::owner();

    Livewire::actingAs($user)->test(Projects::class)
        ->call('startCreate')
        ->set('form.name', 'Lagerlokal Mölndal')
        ->set('form.customer_id', '')
        ->call('save')
        ->assertHasErrors(['form.customer_id' => 'required']);

    expect(Project::query()->count())->toBe(0);
});

it('refuses a deadline before the start date', function (): void {
    $user = PwaFixtures::owner();
    $customer = CrmRecords::customer(PwaFixtures::organizationOf($user), 'Nordlund Logistik');

    Livewire::actingAs($user)->test(Projects::class)
        ->call('startCreate')
        ->set('form.name', 'Lagerlokal Mölndal')
        ->set('form.customer_id', (string) $customer->getKey())
        ->set('form.starts_on', '2026-10-17')
        ->set('form.due_on', '2026-10-01')
        ->call('save')
        ->assertHasErrors('form.due_on');
});

it('offers only this workspace\'s customers in the select', function (): void {
    $user = PwaFixtures::owner();
    $mine = CrmRecords::customer(PwaFixtures::organizationOf($user), 'Nordlund Logistik');
    $theirs = CrmRecords::customer(Organization::factory()->create(), 'Someone Else AB');

    Livewire::actingAs($user)->test(Projects::class)
        ->call('startCreate')
        ->assertSee($mine->display_name)
        ->assertDontSee($theirs->display_name);
});

it('updates a project and filters the list by customer and status', function (): void {
    $user = PwaFixtures::owner();
    $organization = PwaFixtures::organizationOf($user);
    $first = CrmRecords::customer($organization, 'Nordlund Logistik');
    $second = CrmRecords::customer($organization, 'Göteborg Frakt');
    $project = CrmRecords::project($first, 'Lagerlokal Mölndal');
    CrmRecords::project($second, 'Terminalbyte', ['status' => ProjectStatus::Done]);

    Livewire::actingAs($user)->test(Projects::class)
        ->call('startEdit', (string) $project->getKey())
        ->set('form.status', ProjectStatus::OnHold->value)
        ->call('save')
        ->assertHasNoErrors();

    expect($project->fresh()?->status)->toBe(ProjectStatus::OnHold);

    Livewire::actingAs($user)->test(Projects::class)
        ->set('customer', (string) $second->getKey())
        ->assertSee('Terminalbyte')
        ->assertDontSee('Lagerlokal Mölndal')
        ->set('customer', '')
        ->set('status', ProjectStatus::Done->value)
        ->assertSee('Terminalbyte')
        ->assertDontSee('Lagerlokal Mölndal');
});

it('deletes a project only through a proposal and an approval', function (): void {
    $user = PwaFixtures::owner();
    $customer = CrmRecords::customer(PwaFixtures::organizationOf($user), 'Nordlund Logistik');
    $project = CrmRecords::project($customer, 'Lagerlokal Mölndal');

    Livewire::actingAs($user)->test(Projects::class)
        ->call('proposeDeletion', (string) $project->getKey());

    expect($project->fresh()?->trashed())->toBeFalse();

    Livewire::actingAs($user)->test(Projects::class)
        ->call('approveDeletion', (string) $project->getKey());

    expect(Project::withTrashed()->whereKey($project->getKey())->sole()->trashed())->toBeTrue()
        ->and(ActionApproval::query()->count())->toBe(1);
});

it('searches projects through their customer, because projects carry no index of their own', function (): void {
    $user = PwaFixtures::owner();
    $organization = PwaFixtures::organizationOf($user);
    $found = CrmRecords::customer($organization, 'Göteborg Frakt');
    $other = CrmRecords::customer($organization, 'Malmö Montage');
    CrmRecords::project($found, 'Terminalbyte');
    CrmRecords::project($other, 'Lagerlokal Mölndal');

    // ADR-002 D4 indexes customers, contacts and communications only, and the
    // index covers the same text on both profiles. So a term on this screen is
    // resolved through the `customers` corpus — never a LIKE on `name`, which
    // would fold diacritics on one engine and not the other.
    Livewire::actingAs($user)->test(Projects::class)
        ->set('search', 'goteborg')
        ->assertSee('Terminalbyte')
        ->assertDontSee('Lagerlokal Mölndal');
});
