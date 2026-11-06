{{--
    The tasks list. A task with no deadline says so in words rather than showing
    an empty cell: §4.2 forbids inventing one, and "no deadline" is information.
--}}
<table class="w-full min-w-max border-collapse text-left text-md">
    <caption class="sr-only">{{ __('crm.tasks.title') }}</caption>
    <thead class="bg-surface-sunken text-xs font-medium uppercase tracking-wide text-muted">
        <tr>
            <th scope="col" class="px-4 py-3">{{ __('crm.tasks.fields.title') }}</th>
            <th scope="col" class="px-4 py-3">{{ __('crm.fields.customer') }}</th>
            <th scope="col" class="px-4 py-3">{{ __('crm.fields.status') }}</th>
            <th scope="col" class="px-4 py-3">{{ __('crm.tasks.fields.priority') }}</th>
            <th scope="col" class="px-4 py-3">{{ __('crm.tasks.fields.due_at') }}</th>
            <th scope="col" class="px-4 py-3"><span class="sr-only">{{ __('crm.actions.open') }}</span></th>
        </tr>
    </thead>
    <tbody class="divide-y divide-default">
        @foreach($page as $row)
            <tr class="bg-surface" data-crm-row="{{ $row->id }}">
                <th scope="row" class="px-4 py-3 font-medium text-primary">
                    {{ $row->title }}
                    @if($row->trashed())
                        <x-badge tone="warning" class="ml-2">{{ __('crm.deletion.deleted_badge') }}</x-badge>
                    @elseif(isset($pending[$row->id]))
                        <x-badge tone="warning" class="ml-2" data-crm-row-awaiting>{{ __('crm.deletion.awaiting') }}</x-badge>
                    @endif
                </th>
                <td class="px-4 py-3 text-secondary">{{ $row->customer?->display_name ?? __('crm.fields.none') }}</td>
                <td class="px-4 py-3"><x-badge>{{ __("crm.status.task.{$row->status->value}") }}</x-badge></td>
                <td class="px-4 py-3"><x-badge>{{ __("brief.priorities.{$row->priority->value}") }}</x-badge></td>
                <td class="px-4 py-3 text-secondary">
                    @if($row->due_at !== null)
                        <time datetime="{{ $row->due_at->toIso8601String() }}">{{ $row->due_at->toDayDateTimeString() }}</time>
                    @else
                        {{ __('crm.tasks.no_due_date') }}
                    @endif
                </td>
                <td class="px-4 py-3 text-right">
                    <button type="button" wire:click="openRecord('{{ $row->id }}')" class="btn-secondary">{{ __('crm.actions.open') }}</button>
                </td>
            </tr>
        @endforeach
    </tbody>
</table>
