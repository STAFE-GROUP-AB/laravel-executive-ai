<?php

declare(strict_types=1);

namespace App\Livewire\Crm;

use App\CRM\Contacts\ContactEmailLabel;
use App\CRM\Eloquent\ContactAddresses;
use App\CRM\Eloquent\CrmEntity;
use App\CRM\WorkspaceScope;
use App\Models\CRM\Contact;
use App\Models\CRM\CrmModel;
use App\Security\Authorization\Gates\Abilities;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;

/**
 * `/crm/contacts` — natural persons, with **several email addresses each**
 * (§3.2) and the customers they belong to.
 *
 * Two things are more than form plumbing:
 *
 * - **The addresses are rows**, not a JSON field, because §5.6's "the sender
 *   address is contact X's" has to be an indexed, deterministic lookup. They
 *   are unique per workspace, so the form checks a collision itself and shows a
 *   field error rather than letting the unique index answer with a 500. The
 *   check and the lookup both go through `ContactAddresses`, the module's only
 *   call site for matching on an address — S2-04 (#87) encrypts that column.
 * - **A contact is always `personal` data.** `CrmEntity::sensitivityClass()`
 *   states it on write, and the `contact_personal_data` retention rule
 *   anonymises rather than purges, three years off `updated_at` — which every
 *   save moves forward (`RetentionStamp`).
 */
#[Layout('layouts.page')]
#[Title('Contacts')]
final class Contacts extends CrmScreen
{
    /**
     * The addresses being edited: `[{address, label, primary}]`, in the order
     * they will be shown.
     *
     * @var list<array{address: string, label: string, primary: bool}>
     */
    public array $addresses = [];

    /** @var list<string> the customers this contact belongs to */
    public array $customerIds = [];

    public function render(): View
    {
        Gate::authorize(Abilities::VIEW_CRM);

        $page = $this->page();
        $scope = $this->workspace()->scope();

        return view('crm.contacts', [
            'page' => $page,
            'pending' => $this->pendingDeletions($page),
            'customers' => $scope === null ? [] : $this->repository()->customerOptions($scope),
            'labels' => $this->labelOptions(),
            ...$this->recordPanel(),
        ]);
    }

    public function addAddress(): void
    {
        Gate::authorize(Abilities::VIEW_CRM);

        $this->addresses[] = ['address' => '', 'label' => ContactEmailLabel::Work->value, 'primary' => $this->addresses === []];
    }

    public function removeAddress(int $index): void
    {
        Gate::authorize(Abilities::VIEW_CRM);

        unset($this->addresses[$index]);
        $this->addresses = array_values($this->addresses);

        if ($this->addresses !== [] && ! $this->hasPrimary()) {
            $this->addresses[0]['primary'] = true;
        }
    }

    /** Exactly one address is the primary one, so choosing clears the others. */
    public function makePrimary(int $index): void
    {
        Gate::authorize(Abilities::VIEW_CRM);

        foreach (array_keys($this->addresses) as $key) {
            $this->addresses[$key]['primary'] = $key === $index;
        }
    }

    protected function entity(): CrmEntity
    {
        return CrmEntity::Contacts;
    }

    /** @return array<string, list<mixed>> */
    protected function rules(): array
    {
        return [
            'form.given_name' => ['required', 'string', 'max:200'],
            'form.family_name' => ['required', 'string', 'max:200'],
            'form.display_name' => ['required', 'string', 'max:200'],
            'form.title' => ['nullable', 'string', 'max:200'],
            'form.language' => ['required', 'string', 'max:35'],
            'form.notes' => ['nullable', 'string', 'max:20000'],
            'addresses' => ['array', 'max:20'],
            'addresses.*.address' => ['required', 'string', 'email:rfc', 'max:320'],
            'addresses.*.label' => ['required', 'string', 'in:'.implode(',', array_keys($this->labelOptions()))],
            'customerIds' => ['array'],
            'customerIds.*' => ['string', 'size:26'],
        ];
    }

    /** @return array<string, mixed> */
    protected function emptyForm(): array
    {
        $this->addresses = [];
        $this->customerIds = [];

        return [
            'given_name' => '',
            'family_name' => '',
            'display_name' => '',
            'title' => '',
            'language' => 'sv',
            'notes' => '',
        ];
    }

    /** @return array<string, mixed> */
    protected function formFor(CrmModel $row): array
    {
        /** @var Contact $contact */
        $contact = $row;

        $this->addresses = $contact->emails
            ->map(static fn ($email): array => [
                'address' => (string) $email->address,
                'label' => $email->label->value,
                'primary' => (bool) $email->is_primary,
            ])
            ->values()
            ->all();

        $this->customerIds = $contact->customers->pluck('id')->map(static fn (mixed $id): string => (string) $id)->values()->all();

        return [
            'given_name' => $contact->given_name,
            'family_name' => $contact->family_name,
            'display_name' => $contact->display_name,
            'title' => (string) $contact->title,
            'language' => $contact->language,
            'notes' => $contact->notes,
        ];
    }

    /**
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    protected function attributesFrom(array $form): array
    {
        return [
            'given_name' => trim((string) $form['given_name']),
            'family_name' => trim((string) $form['family_name']),
            'display_name' => trim((string) $form['display_name']),
            'title' => trim((string) $form['title']) === '' ? null : trim((string) $form['title']),
            'language' => trim((string) $form['language']),
            'notes' => trim((string) $form['notes']),
        ];
    }

    /**
     * `phones` is a JSON list in the schema because nothing matches on a phone
     * number (§3.2), and this form does not edit it yet. It is a default for a
     * new contact and absent from `attributesFrom()`, so an existing contact's
     * numbers survive an edit of their name.
     *
     * @return array<string, mixed>
     */
    protected function creationDefaults(): array
    {
        return ['phones' => []];
    }

    /**
     * @param  array<string, mixed>  $form
     */
    protected function afterSave(WorkspaceScope $scope, CrmModel $row, array $form, CarbonImmutable $now): void
    {
        /** @var Contact $contact */
        $contact = $row;

        $this->repository()->syncContactEmails($scope, $contact, $this->normalizedAddresses(), $now);
        $this->repository()->syncContactCustomers($scope, $contact, $this->customerIds, $now);
    }

    /**
     * The rules above cover the shape of an address; these two are about the
     * workspace, so they need a query. Both exist to turn a unique-index
     * violation into a field error: the index is still the guarantee, this is
     * only what the person reads.
     */
    protected function validateForm(): void
    {
        parent::validateForm();

        $scope = $this->requireScope();
        $problems = [];
        $seen = [];

        foreach ($this->addresses as $index => $address) {
            $normalized = ContactAddresses::normalize($address['address']);

            if (in_array($normalized, $seen, true)) {
                $problems["addresses.{$index}.address"] = __('crm.contacts.errors.duplicate_address');

                continue;
            }

            $seen[] = $normalized;

            if (ContactAddresses::isTaken($scope->organizationId, $normalized, $this->editing)) {
                $problems["addresses.{$index}.address"] = __('crm.contacts.errors.address_taken');
            }
        }

        if ($problems !== []) {
            throw ValidationException::withMessages($problems);
        }
    }

    /** @return array<string, string> */
    protected function validationLabels(): array
    {
        return [
            'form.given_name' => __('crm.contacts.fields.given_name'),
            'form.family_name' => __('crm.contacts.fields.family_name'),
            'form.display_name' => __('crm.contacts.fields.display_name'),
            'form.title' => __('crm.contacts.fields.title'),
            'form.language' => __('crm.contacts.fields.language'),
            'form.notes' => __('crm.fields.notes'),
            'addresses.*.address' => __('crm.contacts.fields.address'),
            'addresses.*.label' => __('crm.contacts.fields.label'),
        ];
    }

    /** @return array<string, string> */
    protected function detailFields(CrmModel $row): array
    {
        /** @var Contact $contact */
        $contact = $row;

        return [
            __('crm.contacts.fields.given_name') => $contact->given_name,
            __('crm.contacts.fields.family_name') => $contact->family_name,
            __('crm.contacts.fields.title') => (string) $contact->title,
            __('crm.contacts.fields.language') => $contact->language,
            __('crm.contacts.addresses') => $contact->emails->pluck('address')->implode(', '),
            __('crm.contacts.customers') => $contact->customers->pluck('display_name')->implode(', '),
            __('crm.fields.notes') => $contact->notes,
        ];
    }

    /** @return array<string, string> */
    private function labelOptions(): array
    {
        $options = [];

        foreach (ContactEmailLabel::cases() as $label) {
            $options[$label->value] = __("crm.contacts.labels.{$label->value}");
        }

        return $options;
    }

    private function hasPrimary(): bool
    {
        foreach ($this->addresses as $address) {
            if ($address['primary']) {
                return true;
            }
        }

        return false;
    }

    /**
     * The list as it will be stored: blanks dropped, exactly one primary.
     *
     * @return list<array{address: string, label: string, primary: bool}>
     */
    private function normalizedAddresses(): array
    {
        $addresses = [];

        foreach ($this->addresses as $address) {
            $normalized = ContactAddresses::normalize($address['address']);

            if ($normalized === '') {
                continue;
            }

            $addresses[] = [
                'address' => $normalized,
                'label' => $address['label'],
                'primary' => (bool) $address['primary'],
            ];
        }

        if ($addresses === []) {
            return [];
        }

        $primaries = array_keys(array_filter($addresses, static fn (array $address): bool => $address['primary']));

        foreach (array_keys($addresses) as $index) {
            $addresses[$index]['primary'] = $index === ($primaries[0] ?? 0);
        }

        return $addresses;
    }
}
