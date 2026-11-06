{{--
    The contacts list. The primary address is shown because it is the one §5.6
    matches a sender on; the rest are on the detail panel and in the form.
--}}
<table class="w-full min-w-max border-collapse text-left text-md">
    <caption class="sr-only">{{ __('crm.contacts.title') }}</caption>
    <thead class="bg-surface-sunken text-xs font-medium uppercase tracking-wide text-muted">
        <tr>
            <th scope="col" class="px-4 py-3">{{ __('crm.contacts.fields.display_name') }}</th>
            <th scope="col" class="px-4 py-3">{{ __('crm.contacts.fields.address') }}</th>
            <th scope="col" class="px-4 py-3">{{ __('crm.contacts.customers') }}</th>
            <th scope="col" class="px-4 py-3">{{ __('crm.fields.updated') }}</th>
            <th scope="col" class="px-4 py-3"><span class="sr-only">{{ __('crm.actions.open') }}</span></th>
        </tr>
    </thead>
    <tbody class="divide-y divide-default">
        @foreach($page as $row)
            @php
                $primary = $row->emails->firstWhere('is_primary', true) ?? $row->emails->first();
            @endphp
            <tr class="bg-surface" data-crm-row="{{ $row->id }}">
                <th scope="row" class="px-4 py-3 font-medium text-primary">
                    {{ $row->display_name }}
                    @if($row->title !== null)
                        <span class="block text-xs font-normal text-muted">{{ $row->title }}</span>
                    @endif
                    @if($row->trashed())
                        <x-badge tone="warning" class="mt-1">{{ __('crm.deletion.deleted_badge') }}</x-badge>
                    @elseif(isset($pending[$row->id]))
                        <x-badge tone="warning" class="mt-1" data-crm-row-awaiting>{{ __('crm.deletion.awaiting') }}</x-badge>
                    @endif
                </th>
                <td class="px-4 py-3 break-all text-secondary">
                    {{ $primary?->address ?? __('crm.contacts.no_addresses') }}
                    @if($row->emails->count() > 1)
                        <span class="block text-xs text-muted">+{{ $row->emails->count() - 1 }}</span>
                    @endif
                </td>
                <td class="px-4 py-3 text-secondary">{{ $row->customers->pluck('display_name')->implode(', ') ?: __('crm.fields.none') }}</td>
                <td class="px-4 py-3 text-secondary">
                    <time datetime="{{ $row->updated_at?->toIso8601String() }}">{{ $row->updated_at?->toDateString() }}</time>
                </td>
                <td class="px-4 py-3 text-right">
                    <button type="button" wire:click="openRecord('{{ $row->id }}')" class="btn-secondary">{{ __('crm.actions.open') }}</button>
                </td>
            </tr>
        @endforeach
    </tbody>
</table>
