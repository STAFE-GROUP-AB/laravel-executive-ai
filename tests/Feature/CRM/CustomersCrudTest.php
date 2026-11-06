<?php

declare(strict_types=1);

use App\Approvals\ProposalKind;
use App\Approvals\ProposalStatus;
use App\CRM\Customers\CustomerStatus;
use App\Livewire\Crm\Customers;
use App\Models\Approvals\ActionApproval;
use App\Models\Approvals\ActionProposal;
use App\Models\CRM\Customer;
use App\Models\Organization;
use App\Models\User;
use App\Security\Retention\RetentionRules;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\CrmRecords;
use Tests\Support\PwaFixtures;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(DatabaseTransactions::class);

/*
 * S2-09 (#91): `/crm/customers` creates, reads, updates and deletes a customer.
 *
 * The deletion cases carry the most weight. §11 makes CRM deletion
 * approval-driven, so "Delete" here is a *proposal*: the record is still there
 * afterwards, and only a second, recorded approval soft-deletes it. A test that
 * merely asserted the row was gone would pass against a direct `delete()` —
 * which is exactly the implementation §11 forbids — so the assertions are
 * about the two rows the approval trail leaves as much as about the record.
 */

it('requires a signed-in user', function (): void {
    get('/crm/customers')->assertRedirect(route('login'));
});

it('renders the page with the empty state for a workspace with no customers', function (): void {
    $user = PwaFixtures::owner();

    actingAs($user)->get('/crm/customers')->assertOk()
        ->assertSee(__('crm.customers.title'))
        ->assertSee(__('crm.customers.empty'))
        ->assertSee('data-empty-state', false);
});

it('gives a signed-in account with no membership a 200 and an empty state, not a 403', function (): void {
    // pwa.md §2: there is no admin bypass, and the absence of a membership is
    // not an authorization failure of the page — it is an empty workspace.
    $stranger = User::factory()->create();

    actingAs($stranger)->get('/crm/customers')->assertOk()
        ->assertSee(__('crm.no_workspace.title'))
        ->assertSee('data-crm-no-workspace', false);
});

it('lists this workspace\'s customers and never another workspace\'s', function (): void {
    $user = PwaFixtures::owner();
    $mine = CrmRecords::customer(PwaFixtures::organizationOf($user), 'Nordlund Logistik');
    $theirs = CrmRecords::customer(Organization::factory()->create(), 'Someone Else AB');

    actingAs($user)->get('/crm/customers')->assertOk()
        ->assertSee($mine->display_name)
        ->assertDontSee($theirs->display_name);
});

it('creates a customer with the §3.1 lineage and a retention policy that resolves', function (): void {
    $user = PwaFixtures::owner();
    $organization = PwaFixtures::organizationOf($user);

    Livewire::actingAs($user)->test(Customers::class)
        ->call('startCreate')
        ->set('form.display_name', 'Kvarnholmen Entreprenad')
        ->set('form.legal_name', 'Kvarnholmen Entreprenad AB')
        ->set('form.country', 'SE')
        ->set('form.registration_number', '5566778899')
        ->set('form.status', CustomerStatus::Prospect->value)
        ->set('form.email_domains', 'Kvarnholmen.se, shared.example')
        ->set('form.tags', 'bygg')
        ->set('form.notes', 'Första kontakt på mässan.')
        ->call('save')
        ->assertHasNoErrors();

    $customer = Customer::query()->where('display_name', 'Kvarnholmen Entreprenad')->sole();

    expect($customer->organization_id)->toBe((string) $organization->getKey())
        ->and($customer->provenance['actor'])->toBe("user:{$user->id}")
        ->and($customer->provenance['origin'])->toBe('manual')
        ->and($customer->sensitivity_class->value)->toBe('internal')
        // Domains are lower-cased because they are matched, never displayed as typed.
        ->and($customer->email_domains)->toBe(['kvarnholmen.se', 'shared.example'])
        ->and($customer->tags)->toBe(['bygg']);

    // S2-03: a value in `retention_policy` that resolves to nothing is a row
    // the purge job skips forever, so the written value is resolved here.
    expect(RetentionRules::resolve((string) $customer->retention_policy)->governs('customers'))->toBeTrue();
});

it('refuses a customer with no name and keeps the form open', function (): void {
    $user = PwaFixtures::owner();

    Livewire::actingAs($user)->test(Customers::class)
        ->call('startCreate')
        ->set('form.display_name', '')
        ->set('form.legal_name', '')
        ->call('save')
        ->assertHasErrors(['form.display_name' => 'required', 'form.legal_name' => 'required'])
        ->assertSet('formOpen', true);

    expect(Customer::query()->count())->toBe(0);
});

it('updates a customer', function (): void {
    $user = PwaFixtures::owner();
    $customer = CrmRecords::customer(PwaFixtures::organizationOf($user), 'Nordlund Logistik');

    Livewire::actingAs($user)->test(Customers::class)
        ->call('startEdit', (string) $customer->getKey())
        ->assertSet('form.display_name', 'Nordlund Logistik')
        ->set('form.display_name', 'Nordlund Logistik & Lager')
        ->set('form.status', CustomerStatus::Dormant->value)
        ->call('save')
        ->assertHasNoErrors();

    $customer->refresh();

    expect($customer->display_name)->toBe('Nordlund Logistik & Lager')
        ->and($customer->status)->toBe(CustomerStatus::Dormant);
});

it('answers 404 for another workspace\'s record, the same as for one that does not exist', function (): void {
    $user = PwaFixtures::owner();
    $theirs = CrmRecords::customer(Organization::factory()->create(), 'Someone Else AB');

    // The two cases must be indistinguishable: a 403 for the foreign id and a
    // 404 for the unknown one would tell the caller which ULIDs exist
    // elsewhere (authorization.md §4, the same property TenantRowGuard has).
    Livewire::actingAs($user)->test(Customers::class)
        ->call('startEdit', (string) $theirs->getKey())
        ->assertStatus(404);

    Livewire::actingAs($user)->test(Customers::class)
        ->call('startEdit', (string) Str::ulid())
        ->assertStatus(404);
});

it('proposes a deletion instead of deleting, and the record is still there', function (): void {
    $user = PwaFixtures::owner();
    $customer = CrmRecords::customer(PwaFixtures::organizationOf($user), 'Nordlund Logistik');

    Livewire::actingAs($user)->test(Customers::class)
        ->call('proposeDeletion', (string) $customer->getKey())
        ->assertSee(__('crm.deletion.proposed'));

    $proposal = ActionProposal::query()->sole();

    expect($customer->fresh()?->trashed())->toBeFalse('the record was deleted without an approval')
        ->and($proposal->kind)->toBe(ProposalKind::CrmDeletion)
        ->and($proposal->status)->toBe(ProposalStatus::Pending)
        ->and($proposal->source_id)->toBe('customers:'.$customer->getKey())
        ->and($proposal->proposed_by)->toBe("user:{$user->id}")
        ->and($proposal->hashMatchesPayload())->toBeTrue()
        ->and($proposal->payload['record_id'])->toBe((string) $customer->getKey())
        ->and(ActionApproval::query()->count())->toBe(0);
});

it('proposes once when the Delete button is pressed twice', function (): void {
    $user = PwaFixtures::owner();
    $customer = CrmRecords::customer(PwaFixtures::organizationOf($user), 'Nordlund Logistik');

    Livewire::actingAs($user)->test(Customers::class)
        ->call('proposeDeletion', (string) $customer->getKey())
        ->call('proposeDeletion', (string) $customer->getKey());

    expect(ActionProposal::query()->count())->toBe(1);
});

it('soft-deletes only after an approval, and records who approved what', function (): void {
    $user = PwaFixtures::owner();
    $customer = CrmRecords::customer(PwaFixtures::organizationOf($user), 'Nordlund Logistik');

    $component = Livewire::actingAs($user)->test(Customers::class)
        ->call('proposeDeletion', (string) $customer->getKey())
        ->call('approveDeletion', (string) $customer->getKey())
        ->assertSee(__('crm.deletion.done'));

    $proposal = ActionProposal::query()->sole();
    $approval = ActionApproval::query()->sole();
    $deleted = Customer::withTrashed()->whereKey($customer->getKey())->sole();

    expect($deleted->trashed())->toBeTrue()
        ->and($proposal->status)->toBe(ProposalStatus::Executed)
        ->and($proposal->executed_at)->not->toBeNull()
        // §11: the approval names the approver and carries the hash they saw,
        // so the consent is bound to the content rather than to the row id.
        ->and($approval->approver_user_id)->toBe((string) $user->id)
        ->and($approval->proposal_hash)->toBe($proposal->proposal_hash)
        ->and($approval->nonce)->toBe($proposal->nonce)
        // The soft delete keeps the retention evidence: `crm_records` is
        // keep_until_erased off `deleted_at`, so the clock starts here and the
        // purge job — not a user — decides when the row leaves.
        ->and((string) $deleted->retention_policy)->toBe('crm_records')
        ->and(RetentionRules::resolve('crm_records')->neverExpires())->toBeTrue()
        ->and($deleted->retain_until)->toBeNull();

    $component->assertSet('problem', null);
});

it('refuses to delete when the proposal has lapsed, and says so in the error state', function (): void {
    $user = PwaFixtures::owner();
    $customer = CrmRecords::customer(PwaFixtures::organizationOf($user), 'Nordlund Logistik');

    Livewire::actingAs($user)->test(Customers::class)->call('proposeDeletion', (string) $customer->getKey());

    // `expires_at` is outside MUTABLE_AFTER_FREEZE, so the frozen-proposal
    // guard refuses an ordinary save; lapsing it is a database fact, not a
    // user action, which is why the test writes the column directly.
    DB::table('action_proposals')
        ->where('id', ActionProposal::query()->sole()->getKey())
        ->update(['expires_at' => now()->subMinute()]);

    $component = Livewire::actingAs($user)->test(Customers::class)
        ->call('approveDeletion', (string) $customer->getKey());

    expect($component->get('problem'))->toBeString()
        ->and($customer->fresh()?->trashed())->toBeFalse('a lapsed proposal still deleted the record')
        ->and(ActionApproval::query()->count())->toBe(0);

    $component->assertSee('data-crm-problem', false);
});

it('withdraws a proposed deletion without touching the record', function (): void {
    $user = PwaFixtures::owner();
    $customer = CrmRecords::customer(PwaFixtures::organizationOf($user), 'Nordlund Logistik');

    Livewire::actingAs($user)->test(Customers::class)
        ->call('proposeDeletion', (string) $customer->getKey())
        ->call('rejectDeletion', (string) $customer->getKey())
        ->assertSee(__('crm.deletion.withdrawn'));

    expect($customer->fresh()?->trashed())->toBeFalse()
        ->and(ActionProposal::query()->sole()->status)->toBe(ProposalStatus::Rejected);
});

it('restores a deleted customer without an approval, because restoring destroys nothing', function (): void {
    $user = PwaFixtures::owner();
    $customer = CrmRecords::customer(PwaFixtures::organizationOf($user), 'Nordlund Logistik');

    Livewire::actingAs($user)->test(Customers::class)
        ->call('proposeDeletion', (string) $customer->getKey())
        ->call('approveDeletion', (string) $customer->getKey())
        ->set('showDeleted', true)
        ->call('restoreRecord', (string) $customer->getKey())
        ->assertSee(__('crm.restored'));

    expect(Customer::query()->whereKey($customer->getKey())->exists())->toBeTrue();
});

it('searches through the full-text corpus rather than a LIKE', function (): void {
    $user = PwaFixtures::owner();
    $organization = PwaFixtures::organizationOf($user);
    $found = CrmRecords::customer($organization, 'Göteborg Frakt');
    CrmRecords::customer($organization, 'Malmö Montage');

    // Diacritics folded by the index (unicode61 remove_diacritics 2 / utf8mb4_unicode_ci),
    // which a LIKE over the raw column would not do.
    Livewire::actingAs($user)->test(Customers::class)
        ->set('search', 'goteborg')
        ->assertSee($found->display_name)
        ->assertDontSee('Malmö Montage');
});

it('says something useful when a search matches nothing', function (): void {
    $user = PwaFixtures::owner();
    CrmRecords::customer(PwaFixtures::organizationOf($user), 'Nordlund Logistik');

    Livewire::actingAs($user)->test(Customers::class)
        ->set('search', 'kvarnholmen')
        ->assertSee(__('crm.customers.empty_search'))
        ->assertSee(__('crm.filters.clear'));
});

it('paginates, and a filter resets the page', function (): void {
    $user = PwaFixtures::owner();
    $organization = PwaFixtures::organizationOf($user);

    foreach (range(1, 25) as $index) {
        CrmRecords::customer($organization, sprintf('Kund %02d', $index));
    }

    Livewire::actingAs($user)->test(Customers::class)
        ->assertSee('Kund 01')
        ->assertDontSee('Kund 25')
        ->call('nextPage')
        ->assertSee('Kund 25')
        ->set('search', 'kund')
        ->assertSet('paginators.page', 1);
});
