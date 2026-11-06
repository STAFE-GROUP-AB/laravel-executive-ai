{{-- The projects list. `due_on` is a calendar date, so no time is shown. --}}
<table class="w-full min-w-max border-collapse text-left text-md">
    <caption class="sr-only">{{ __('crm.projects.title') }}</caption>
    <thead class="bg-surface-sunken text-xs font-medium uppercase tracking-wide text-muted">
        <tr>
            <th scope="col" class="px-4 py-3">{{ __('crm.projects.fields.name') }}</th>
            <th scope="col" class="px-4 py-3">{{ __('crm.fields.customer') }}</th>
            <th scope="col" class="px-4 py-3">{{ __('crm.fields.status') }}</th>
            <th scope="col" class="px-4 py-3">{{ __('crm.projects.fields.due_on') }}</th>
            <th scope="col" class="px-4 py-3"><span class="sr-only">{{ __('crm.actions.open') }}</span></th>
        </tr>
    </thead>
    <tbody class="divide-y divide-default">
        @foreach($page as $row)
            <tr class="bg-surface" data-crm-row="{{ $row->id }}">
                <th scope="row" class="px-4 py-3 font-medium text-primary">
                    {{ $row->name }}
                    @if($row->trashed())
                        <x-badge tone="warning" class="ml-2">{{ __('crm.deletion.deleted_badge') }}</x-badge>
                    @elseif(isset($pending[$row->id]))
                        <x-badge tone="warning" class="ml-2" data-crm-row-awaiting>{{ __('crm.deletion.awaiting') }}</x-badge>
                    @endif
                </th>
                <td class="px-4 py-3 text-secondary">{{ $row->customer?->display_name }}</td>
                <td class="px-4 py-3"><x-badge>{{ __("crm.status.project.{$row->status->value}") }}</x-badge></td>
                <td class="px-4 py-3 text-secondary">
                    @if($row->due_on !== null)
                        <time datetime="{{ $row->due_on->toDateString() }}">{{ $row->due_on->toDateString() }}</time>
                    @else
                        {{ __('crm.fields.none') }}
                    @endif
                </td>
                <td class="px-4 py-3 text-right">
                    <button type="button" wire:click="openRecord('{{ $row->id }}')" class="btn-secondary">{{ __('crm.actions.open') }}</button>
                </td>
            </tr>
        @endforeach
    </tbody>
</table>
