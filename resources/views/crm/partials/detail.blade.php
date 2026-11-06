{{--
    The detail panel for the open record: a definition list built from the
    screen's own `detailFields()`, plus the actions for this record.

    The §11 deletion lives here rather than on the row, because this is where
    the person is looking at what they are about to remove. Delete proposes;
    approving is a second, separate click that records who approved. A record
    with a pending proposal says so and shows both ways out.

    Expects: $record, $recordLabel, $details, $pendingForRecord.
--}}
<section class="card mt-6" aria-labelledby="crm-detail-title" data-crm-detail="{{ $record->getKey() }}">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <h2 id="crm-detail-title" class="text-lg font-semibold break-words">{{ $recordLabel }}</h2>
            <p class="mt-1 text-xs text-muted">
                {{ __('crm.fields.updated') }}
                <time datetime="{{ $record->updated_at?->toIso8601String() }}">{{ $record->updated_at?->toDayDateTimeString() }}</time>
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @if($record->trashed())
                <x-badge tone="warning" data-crm-deleted>{{ __('crm.deletion.deleted_badge') }}</x-badge>
            @endif
            <button type="button" wire:click="closePanels" class="btn-secondary">{{ __('crm.actions.close') }}</button>
        </div>
    </div>

    <dl class="mt-5 grid gap-x-6 gap-y-4 sm:grid-cols-2">
        @foreach($details as $label => $value)
            <div class="min-w-0">
                <dt class="text-xs font-medium uppercase tracking-wide text-muted">{{ $label }}</dt>
                <dd class="mt-1 text-md break-words text-primary">{{ $value === '' ? __('crm.fields.none') : $value }}</dd>
            </div>
        @endforeach
    </dl>

    @if($pendingForRecord !== null)
        <div class="mt-6 rounded-md bg-warning-surface p-4 text-warning-text" role="status" data-crm-awaiting-approval>
            <p class="text-md font-semibold">{{ __('crm.deletion.awaiting') }}</p>
            <p class="mt-1 max-w-readable text-sm">{{ __('crm.deletion.awaiting_body') }}</p>
            <p class="mt-1 text-xs">
                {{ __('crm.deletion.expires', ['when' => $pendingForRecord->expires_at->toDayDateTimeString()]) }}
            </p>
            <div class="mt-4 flex flex-wrap gap-3">
                <button type="button" wire:click="approveDeletion('{{ $record->getKey() }}')" class="btn-primary" data-crm-approve-delete>
                    {{ __('crm.actions.approve_delete') }}
                </button>
                <button type="button" wire:click="rejectDeletion('{{ $record->getKey() }}')" class="btn-secondary">
                    {{ __('crm.actions.withdraw_delete') }}
                </button>
            </div>
        </div>
    @endif

    <div class="mt-6 flex flex-wrap gap-3 border-t border-default pt-4">
        @if($record->trashed())
            <button type="button" wire:click="restoreRecord('{{ $record->getKey() }}')" class="btn-primary" data-crm-restore">
                {{ __('crm.actions.restore') }}
            </button>
        @else
            <button type="button" wire:click="startEdit('{{ $record->getKey() }}')" class="btn-secondary">{{ __('crm.actions.edit') }}</button>

            @if($pendingForRecord === null)
                <button type="button" wire:click="proposeDeletion('{{ $record->getKey() }}')" class="btn-secondary" data-crm-delete>
                    {{ __('crm.actions.delete') }}
                </button>
            @endif
        @endif
    </div>
</section>
