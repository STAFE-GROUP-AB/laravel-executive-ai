{{--
    The contact form. Two parts the other screens do not have: the list of
    addresses (§3.2, a person may write from several) and the customers the
    person belongs to.

    The address list is a <fieldset> with its own <legend>, so a screen reader
    announces what the group is before reading the rows; each row's remove
    button names which address it removes, because "Remove" alone is ambiguous
    once there are three of them.
--}}
<div class="grid gap-4 sm:grid-cols-2">
    <x-crm.field name="form.given_name" :label="__('crm.contacts.fields.given_name')" required />
    <x-crm.field name="form.family_name" :label="__('crm.contacts.fields.family_name')" required />
    <x-crm.field name="form.display_name" :label="__('crm.contacts.fields.display_name')" required />
    <x-crm.field name="form.title" :label="__('crm.contacts.fields.title')" />
    <x-crm.field name="form.language" :label="__('crm.contacts.fields.language')" required placeholder="sv" />
</div>

<fieldset class="rounded-md border border-default p-4">
    <legend class="px-1 text-sm font-medium text-primary">{{ __('crm.contacts.addresses') }}</legend>
    <p class="text-xs text-muted">{{ __('crm.contacts.addresses_hint') }}</p>

    @if($addresses === [])
        <p class="mt-3 text-sm text-secondary">{{ __('crm.contacts.no_addresses') }}</p>
    @endif

    <ul class="mt-3 space-y-4">
        @foreach($addresses as $index => $address)
            <li class="grid gap-3 sm:grid-cols-[2fr_1fr_auto_auto] sm:items-end" wire:key="address-{{ $index }}">
                <x-crm.field
                    :name="'addresses.'.$index.'.address'"
                    :label="__('crm.contacts.fields.address')"
                    type="email"
                    required
                />
                <x-crm.field
                    :name="'addresses.'.$index.'.label'"
                    :label="__('crm.contacts.fields.label')"
                    type="select"
                    :options="$labels"
                    required
                />

                @if($address['primary'])
                    <p class="flex min-h-touch items-center">
                        <x-badge tone="accent">{{ __('crm.contacts.primary') }}</x-badge>
                    </p>
                @else
                    <button type="button" wire:click="makePrimary({{ $index }})" class="btn-secondary">{{ __('crm.contacts.make_primary') }}</button>
                @endif

                <button
                    type="button"
                    wire:click="removeAddress({{ $index }})"
                    class="btn-secondary"
                    aria-label="{{ __('crm.contacts.remove_address') }}: {{ $address['address'] === '' ? __('crm.fields.none') : $address['address'] }}"
                >&times;</button>
            </li>
        @endforeach
    </ul>

    <button type="button" wire:click="addAddress" class="btn-secondary mt-4" data-crm-add-address>{{ __('crm.contacts.add_address') }}</button>
</fieldset>

<fieldset class="rounded-md border border-default p-4">
    <legend class="px-1 text-sm font-medium text-primary">{{ __('crm.contacts.customers') }}</legend>
    <p class="text-xs text-muted">{{ __('crm.contacts.customers_hint') }}</p>

    @if($customers === [])
        <p class="mt-3 text-sm text-secondary">{{ __('crm.customers.empty') }}</p>
    @else
        <ul class="mt-3 grid gap-1 sm:grid-cols-2">
            @foreach($customers as $customerId => $customerName)
                <li>
                    <label class="flex min-h-touch items-center gap-2 text-md text-primary">
                        <input
                            type="checkbox"
                            value="{{ $customerId }}"
                            wire:model="customerIds"
                            class="size-5 rounded-sm border-strong text-accent focus-visible:ring-2 focus-visible:ring-focus"
                        >
                        <span class="min-w-0 break-words">{{ $customerName }}</span>
                    </label>
                </li>
            @endforeach
        </ul>
    @endif
</fieldset>

<x-crm.field name="form.notes" :label="__('crm.fields.notes')" type="textarea" />
