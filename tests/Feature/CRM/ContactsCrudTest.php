<?php

declare(strict_types=1);

use App\CRM\Contacts\ContactEmailLabel;
use App\CRM\Eloquent\ContactAddresses;
use App\Livewire\Crm\Contacts;
use App\Models\Approvals\ActionApproval;
use App\Models\CRM\Contact;
use App\Models\CRM\ContactEmail;
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
 * S2-09 (#91): `/crm/contacts`.
 *
 * The addresses are the part worth testing hardest. §3.2 gives a contact
 * several of them and §5.6 makes the exact address the deterministic way to
 * recognise a sender, so they are rows with a unique index per workspace — and
 * the cases below are the ones a JSON column would have made impossible to
 * state: one primary address, a collision reported as a field error rather than
 * a 500, and an address that was removed and added again landing back on the
 * same row instead of colliding with its own tombstone.
 *
 * `it finds a contact by any of its addresses` and `it refuses an address
 * another contact already uses` are also what holds the lookup together with
 * S2-04 (#87), which encrypts `contact_emails.address` and moves the unique
 * index onto `address_hash`: the column can no longer be matched in SQL, so
 * both cases resolve through `ContactEmail::forAddress()` by way of
 * `App\CRM\Eloquent\ContactAddresses` — this module's only call site for
 * matching on an address, and the reason that change touched two lines.
 */

it('requires a signed-in user', function (): void {
    get('/crm/contacts')->assertRedirect(route('login'));
});

it('renders the page with its empty state', function (): void {
    actingAs(PwaFixtures::owner())->get('/crm/contacts')->assertOk()
        ->assertSee(__('crm.contacts.title'))
        ->assertSee(__('crm.contacts.empty'));
});

it('lists a contact with its primary address and its customers, and nobody else\'s', function (): void {
    $user = PwaFixtures::owner();
    $organization = PwaFixtures::organizationOf($user);
    $customer = CrmRecords::customer($organization, 'Nordlund Logistik');
    $contact = CrmRecords::contact($organization, 'Elin Sjöström');
    CrmRecords::address($contact, 'elin@nordlund.example');
    CrmRecords::link($customer, $contact);

    $theirs = CrmRecords::contact(Organization::factory()->create(), 'Someone Else');

    actingAs($user)->get('/crm/contacts')->assertOk()
        ->assertSee('Elin Sjöström')
        ->assertSee('elin@nordlund.example')
        ->assertSee('Nordlund Logistik')
        ->assertDontSee($theirs->display_name);
});

it('creates a contact with several addresses, one of them primary', function (): void {
    $user = PwaFixtures::owner();

    Livewire::actingAs($user)->test(Contacts::class)
        ->call('startCreate')
        ->set('form.given_name', 'Francesca')
        ->set('form.family_name', 'Conti')
        ->set('form.display_name', 'Francesca Conti')
        ->set('form.language', 'it')
        ->call('addAddress')
        ->set('addresses.0.address', 'Francesca.Conti@Studio.example')
        ->call('addAddress')
        ->set('addresses.1.address', 'f.conti@board.example')
        ->set('addresses.1.label', ContactEmailLabel::Other->value)
        ->call('makePrimary', 1)
        ->call('save')
        ->assertHasNoErrors();

    $contact = Contact::query()->where('display_name', 'Francesca Conti')->sole();
    $addresses = $contact->emails;

    expect($addresses)->toHaveCount(2)
        // Stored lower-cased, which is what makes the §5.6 match deterministic.
        ->and($addresses->pluck('address')->all())->toBe(['francesca.conti@studio.example', 'f.conti@board.example'])
        ->and($addresses->pluck('position')->all())->toBe([0, 1])
        ->and($addresses->where('is_primary', true)->pluck('address')->all())->toBe(['f.conti@board.example'])
        // A contact is personal data whatever the column default says.
        ->and($contact->sensitivity_class->value)->toBe('personal')
        ->and($addresses->first()?->sensitivity_class->value)->toBe('personal');

    $rule = RetentionRules::resolve((string) $contact->retention_policy);

    expect($rule->name)->toBe('contact_personal_data')
        ->and($rule->governs('contacts'))->toBeTrue()
        // Three years off `updated_at`, so the stamp is a date and not null.
        ->and($contact->retain_until)->not->toBeNull();
});

it('finds a contact by any of its addresses', function (): void {
    $user = PwaFixtures::owner();
    $organization = PwaFixtures::organizationOf($user);
    $contact = CrmRecords::contact($organization, 'Francesca Conti');
    CrmRecords::address($contact, 'francesca.conti@studio.example', primary: true, position: 0);
    CrmRecords::address($contact, 'f.conti@board.example', primary: false, position: 1);

    $found = ContactAddresses::contactIdsFor((string) $organization->getKey(), 'F.Conti@Board.Example');

    expect($found)->toBe([(string) $contact->getKey()]);
});

it('refuses an address another contact already uses, as a field error', function (): void {
    $user = PwaFixtures::owner();
    $organization = PwaFixtures::organizationOf($user);
    $taken = CrmRecords::contact($organization, 'Elin Sjöström');
    CrmRecords::address($taken, 'elin@nordlund.example');

    Livewire::actingAs($user)->test(Contacts::class)
        ->call('startCreate')
        ->set('form.given_name', 'Erik')
        ->set('form.family_name', 'Nord')
        ->set('form.display_name', 'Erik Nord')
        ->set('form.language', 'sv')
        ->call('addAddress')
        ->set('addresses.0.address', 'Elin@Nordlund.example')
        ->call('save')
        ->assertHasErrors('addresses.0.address');

    expect(Contact::query()->count())->toBe(1, 'the contact was created despite the collision');
});

it('refuses the same address twice in one form', function (): void {
    $user = PwaFixtures::owner();

    Livewire::actingAs($user)->test(Contacts::class)
        ->call('startCreate')
        ->set('form.given_name', 'Erik')
        ->set('form.family_name', 'Nord')
        ->set('form.display_name', 'Erik Nord')
        ->set('form.language', 'sv')
        ->call('addAddress')
        ->set('addresses.0.address', 'erik@nord.example')
        ->call('addAddress')
        ->set('addresses.1.address', 'Erik@Nord.example')
        ->call('save')
        ->assertHasErrors('addresses.1.address');
});

it('refuses something that is not an email address', function (): void {
    $user = PwaFixtures::owner();

    Livewire::actingAs($user)->test(Contacts::class)
        ->call('startCreate')
        ->set('form.given_name', 'Erik')
        ->set('form.family_name', 'Nord')
        ->set('form.display_name', 'Erik Nord')
        ->set('form.language', 'sv')
        ->call('addAddress')
        ->set('addresses.0.address', 'not-an-address')
        ->call('save')
        ->assertHasErrors('addresses.0.address');
});

it('removes an address by soft-deleting it, and puts the same one back on the same row', function (): void {
    $user = PwaFixtures::owner();
    $organization = PwaFixtures::organizationOf($user);
    $contact = CrmRecords::contact($organization, 'Elin Sjöström');
    $kept = CrmRecords::address($contact, 'elin@nordlund.example', primary: true, position: 0);
    $removed = CrmRecords::address($contact, 'elin.sjostrom@nordlund.example', primary: false, position: 1);

    Livewire::actingAs($user)->test(Contacts::class)
        ->call('startEdit', (string) $contact->getKey())
        ->call('removeAddress', 1)
        ->call('save')
        ->assertHasNoErrors();

    expect($contact->fresh()?->emails->pluck('address')->all())->toBe(['elin@nordlund.example'])
        // Soft-deleted, never destroyed: the row is personal data under a
        // retention rule, and forceDelete would take the evidence with it.
        ->and(ContactEmail::withTrashed()->whereKey($removed->getKey())->sole()->trashed())->toBeTrue();

    // Putting it back lands on the original row rather than colliding with its
    // own tombstone — the unique index does not know about `deleted_at`.
    Livewire::actingAs($user)->test(Contacts::class)
        ->call('startEdit', (string) $contact->getKey())
        ->call('addAddress')
        ->set('addresses.1.address', 'elin.sjostrom@nordlund.example')
        ->call('save')
        ->assertHasNoErrors();

    $restored = ContactEmail::withTrashed()->whereKey($removed->getKey())->sole();

    expect($restored->trashed())->toBeFalse()
        ->and($restored->position)->toBe(1)
        ->and(ContactEmail::query()->where('contact_id', $contact->getKey())->count())->toBe(2)
        ->and($kept->fresh()?->position)->toBe(0);
});

it('keeps a phone number the form does not edit', function (): void {
    $user = PwaFixtures::owner();
    $contact = CrmRecords::contact(PwaFixtures::organizationOf($user), 'Elin Sjöström', [
        'phones' => [['number' => '+46 70 123 45 67', 'label' => 'mobile']],
    ]);

    Livewire::actingAs($user)->test(Contacts::class)
        ->call('startEdit', (string) $contact->getKey())
        ->set('form.display_name', 'Elin Sjöström-Nord')
        ->call('save')
        ->assertHasNoErrors();

    $contact->refresh();

    // `phones` is a not-null JSON column the form does not edit. A save that
    // wrote it every time would clear it on an unrelated edit, which is why it
    // is a creation default and not part of `attributesFrom()`.
    expect($contact->display_name)->toBe('Elin Sjöström-Nord')
        ->and($contact->phones)->toBe([['number' => '+46 70 123 45 67', 'label' => 'mobile']]);
});

it('links a contact to more than one customer, and unlinks by soft-deleting the link', function (): void {
    $user = PwaFixtures::owner();
    $organization = PwaFixtures::organizationOf($user);
    $firm = CrmRecords::customer($organization, 'Studio Legale');
    $client = CrmRecords::customer($organization, 'Ferrari Montaggi');
    $contact = CrmRecords::contact($organization, 'Francesca Conti');

    Livewire::actingAs($user)->test(Contacts::class)
        ->call('startEdit', (string) $contact->getKey())
        ->set('customerIds', [(string) $firm->getKey(), (string) $client->getKey()])
        ->call('save')
        ->assertHasNoErrors();

    expect($contact->fresh()?->customers->pluck('display_name')->sort()->values()->all())
        ->toBe(['Ferrari Montaggi', 'Studio Legale']);

    Livewire::actingAs($user)->test(Contacts::class)
        ->call('startEdit', (string) $contact->getKey())
        ->set('customerIds', [(string) $firm->getKey()])
        ->call('save')
        ->assertHasNoErrors();

    expect($contact->fresh()?->customers->pluck('display_name')->all())->toBe(['Studio Legale']);
});

it('drops a customer id from another workspace instead of writing it', function (): void {
    $user = PwaFixtures::owner();
    $organization = PwaFixtures::organizationOf($user);
    $contact = CrmRecords::contact($organization, 'Elin Sjöström');
    $theirs = CrmRecords::customer(Organization::factory()->create(), 'Someone Else AB');

    Livewire::actingAs($user)->test(Contacts::class)
        ->call('startEdit', (string) $contact->getKey())
        ->set('customerIds', [(string) $theirs->getKey()])
        ->call('save')
        ->assertHasNoErrors();

    expect($contact->fresh()?->customers)->toHaveCount(0);
});

it('deletes a contact only through a proposal and an approval', function (): void {
    $user = PwaFixtures::owner();
    $contact = CrmRecords::contact(PwaFixtures::organizationOf($user), 'Elin Sjöström');

    Livewire::actingAs($user)->test(Contacts::class)
        ->call('proposeDeletion', (string) $contact->getKey());

    expect($contact->fresh()?->trashed())->toBeFalse()
        ->and(ActionApproval::query()->count())->toBe(0);

    Livewire::actingAs($user)->test(Contacts::class)
        ->call('approveDeletion', (string) $contact->getKey());

    $deleted = Contact::withTrashed()->whereKey($contact->getKey())->sole();

    expect($deleted->trashed())->toBeTrue()
        ->and(ActionApproval::query()->sole()->approver_user_id)->toBe((string) $user->id)
        // `contact_personal_data` counts from `updated_at`, which the delete
        // moved, so the derived date moved with it.
        ->and($deleted->retain_until)->not->toBeNull();
});

it('searches names and notes through the corpus, and not addresses', function (): void {
    $user = PwaFixtures::owner();
    $organization = PwaFixtures::organizationOf($user);
    $contact = CrmRecords::contact($organization, 'Elin Sjöström');
    CrmRecords::address($contact, 'muessig@nordlund.example');
    CrmRecords::contact($organization, 'Erik Nord');

    // Folded diacritics on the name…
    Livewire::actingAs($user)->test(Contacts::class)
        ->set('search', 'sjostrom')
        ->assertSee('Elin Sjöström')
        ->assertDontSee('Erik Nord');

    // …and nothing for an address, which the `contacts` corpus does not index
    // on either profile (CrmSearchContractTest, "addresses are not indexed").
    Livewire::actingAs($user)->test(Contacts::class)
        ->set('search', 'muessig')
        ->assertSee(__('crm.contacts.empty_search'));
});
