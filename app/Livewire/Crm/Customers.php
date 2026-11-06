<?php

declare(strict_types=1);

namespace App\Livewire\Crm;

use App\CRM\Customers\CustomerStatus;
use App\CRM\Eloquent\CrmEntity;
use App\Models\CRM\CrmModel;
use App\Models\CRM\Customer;
use App\Security\Authorization\Gates\Abilities;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;

/**
 * `/crm/customers` — customers with a legal identity (§3.2): the registered
 * name and number, the country, the owner, the status, the domains the matcher
 * may use as a hint and the free text.
 *
 * `email_domains` is a hint and never a key: §5.6 is explicit that two
 * customers may share a domain, so the form accepts a duplicate without
 * complaint and the field says so.
 */
#[Layout('layouts.page')]
#[Title('Customers')]
final class Customers extends CrmScreen
{
    public function render(): View
    {
        Gate::authorize(Abilities::VIEW_CRM);

        $page = $this->page();

        return view('crm.customers', [
            'page' => $page,
            'pending' => $this->pendingDeletions($page),
            'statuses' => $this->statusOptions(),
            ...$this->recordPanel(),
        ]);
    }

    protected function entity(): CrmEntity
    {
        return CrmEntity::Customers;
    }

    /** @return array<string, list<mixed>> */
    protected function rules(): array
    {
        return [
            'form.display_name' => ['required', 'string', 'max:200'],
            'form.legal_name' => ['required', 'string', 'max:200'],
            'form.country' => ['required', 'string', 'size:2', 'alpha'],
            'form.registration_number' => ['nullable', 'string', 'max:50'],
            'form.website' => ['nullable', 'url:http,https', 'max:2000'],
            'form.status' => ['required', 'string', 'in:'.implode(',', array_keys($this->statusOptions()))],
            'form.email_domains' => ['nullable', 'string', 'max:1000'],
            'form.tags' => ['nullable', 'string', 'max:1000'],
            'form.notes' => ['nullable', 'string', 'max:20000'],
        ];
    }

    /** @return array<string, mixed> */
    protected function emptyForm(): array
    {
        return [
            'display_name' => '',
            'legal_name' => '',
            'country' => 'SE',
            'registration_number' => '',
            'website' => '',
            'status' => CustomerStatus::Prospect->value,
            'email_domains' => '',
            'tags' => '',
            'notes' => '',
        ];
    }

    /** @return array<string, mixed> */
    protected function formFor(CrmModel $row): array
    {
        /** @var Customer $customer */
        $customer = $row;

        return [
            'display_name' => $customer->display_name,
            'legal_name' => $customer->legal_name,
            'country' => $customer->country,
            'registration_number' => (string) $customer->registration_number,
            'website' => (string) $customer->website,
            'status' => $customer->status->value,
            'email_domains' => implode(', ', $customer->email_domains),
            'tags' => implode(', ', $customer->tags),
            'notes' => $customer->notes,
        ];
    }

    /**
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    protected function attributesFrom(array $form): array
    {
        return [
            'display_name' => trim((string) $form['display_name']),
            'legal_name' => trim((string) $form['legal_name']),
            'country' => mb_strtoupper(trim((string) $form['country'])),
            'registration_number' => self::nullIfBlank((string) $form['registration_number']),
            'website' => self::nullIfBlank((string) $form['website']),
            'status' => (string) $form['status'],
            'email_domains' => self::list((string) $form['email_domains'], lowercase: true),
            'tags' => self::list((string) $form['tags'], lowercase: false),
            'notes' => trim((string) $form['notes']),
        ];
    }

    /** @return array<string, string> */
    protected function detailFields(CrmModel $row): array
    {
        /** @var Customer $customer */
        $customer = $row;

        return [
            __('crm.customers.fields.legal_name') => $customer->legal_name,
            __('crm.customers.fields.country') => $customer->country,
            __('crm.customers.fields.registration_number') => (string) $customer->registration_number,
            __('crm.customers.fields.website') => (string) $customer->website,
            __('crm.fields.status') => __("crm.status.customer.{$customer->status->value}"),
            __('crm.customers.fields.owner') => (string) ($customer->owner?->getAttribute('name') ?? ''),
            __('crm.customers.fields.email_domains') => implode(', ', $customer->email_domains),
            __('crm.customers.fields.tags') => implode(', ', $customer->tags),
            __('crm.fields.notes') => $customer->notes,
        ];
    }

    /** @return array<string, string> */
    protected function statusOptions(): array
    {
        $options = [];

        foreach (CustomerStatus::cases() as $status) {
            $options[$status->value] = __("crm.status.customer.{$status->value}");
        }

        return $options;
    }

    /** @return array<string, string> */
    protected function validationLabels(): array
    {
        return [
            'form.display_name' => __('crm.customers.fields.display_name'),
            'form.legal_name' => __('crm.customers.fields.legal_name'),
            'form.country' => __('crm.customers.fields.country'),
            'form.registration_number' => __('crm.customers.fields.registration_number'),
            'form.website' => __('crm.customers.fields.website'),
            'form.status' => __('crm.fields.status'),
            'form.email_domains' => __('crm.customers.fields.email_domains'),
            'form.tags' => __('crm.customers.fields.tags'),
            'form.notes' => __('crm.fields.notes'),
        ];
    }

    private static function nullIfBlank(string $value): ?string
    {
        return trim($value) === '' ? null : trim($value);
    }

    /**
     * A comma-separated field as the JSON list the column stores. Blank entries
     * are dropped and the order the person typed is kept.
     *
     * @return list<string>
     */
    private static function list(string $value, bool $lowercase): array
    {
        $items = [];

        foreach (explode(',', $value) as $item) {
            $item = trim($item);

            if ($item === '') {
                continue;
            }

            $items[] = $lowercase ? mb_strtolower($item) : $item;
        }

        return array_values(array_unique($items));
    }
}
