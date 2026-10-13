{{-- The customers list. Wide, so the parent wraps it in overflow-x-auto. --}}
<table class="w-full min-w-max border-collapse text-left text-md">
    <caption class="sr-only">{{ __('crm.customers.title') }}</caption>
    <thead class="bg-surface-sunken text-xs font-medium uppercase tracking-wide text-muted">
        <tr>
            <th scope="col" class="px-4 py-3">{{ __('crm.customers.fields.display_name') }}</th>
            <th scope="col" class="px-4 py-3">{{ __('crm.customers.fields.legal_name') }}</th>
            <th scope="col" class="px-4 py-3">{{ __('crm.fields.status') }}</th>
            <th scope="col" class="px-4 py-3">{{ __('crm.fields.updated') }}</th>
            <th scope="col" class="px-4 py-3"><span class="sr-only">{{ __('crm.actions.open') }}</span></th>
        </tr>
    </thead>
    <tbody class="divide-y divide-default">
        @foreach($page as $row)
            <tr class="bg-surface" data-crm-row="{{ $row->id }}">
                <th scope="row" class="px-4 py-3 font-medium text-primary">
                    {{ $row->display_name }}
                    @if($row->trashed())
                        <x-badge tone="warning" class="ml-2">{{ __('crm.deletion.deleted_badge') }}</x-badge>
                    @elseif(isset($pending[$row->id]))
                        <x-badge tone="warning" class="ml-2" data-crm-row-awaiting>{{ __('crm.deletion.awaiting') }}</x-badge>
                    @endif
                </th>
                <td class="px-4 py-3 text-secondary">{{ $row->legal_name }}</td>
                <td class="px-4 py-3"><x-badge>{{ __("crm.status.customer.{$row->status->value}") }}</x-badge></td>
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
