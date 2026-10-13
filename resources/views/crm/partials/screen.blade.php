{{--
    The shell every CRM screen shares (S2-09): heading, the four-way tab row,
    the messages, the search and filters, the form panel, the detail panel, the
    list and the pager. The entity-specific parts are three partials included
    by name — `rows-*`, `form-*` — plus the generic detail list.

    Included rather than made a Blade component on purpose: @include shares the
    Livewire component's scope, so `$formOpen`, `$notice`, `$search` and the
    rest are the component's own properties here, with no prop list to keep in
    step with four screens.

    Responsive and accessible (pwa.md §6): one <h1>, the tab row is a real
    <nav> with aria-current, the table is the only wide element and scrolls
    inside its own container, every control is at least 44 px, state is never
    colour alone (each badge carries a word), and the form panel is a <form>
    whose submit button works from the keyboard.

    Expects: $entity (string), $page (paginator), $pending, $record, $details,
    $pendingForRecord, and optionally $statuses, $customers.
--}}
@php
    $screens = ['customers', 'contacts', 'projects', 'tasks'];
    $statuses = $statuses ?? [];
    $customerOptions = $customers ?? [];
    $filtered = $search !== '' || $status !== '' || $customer !== '' || $showDeleted;
    $emptyTitle = $filtered ? __("crm.{$entity}.empty_search") : __("crm.{$entity}.empty");
    $emptyBody = $filtered ? __("crm.{$entity}.empty_search_body") : __("crm.{$entity}.empty_body");
@endphp

<div data-crm-screen="{{ $entity }}">
    <header>
        <h1 class="text-2xl font-semibold">{{ __("crm.{$entity}.title") }}</h1>
        <p class="mt-2 max-w-readable text-md text-secondary">{{ __("crm.{$entity}.lead") }}</p>
    </header>

    <nav aria-label="{{ __('crm.nav') }}" class="mt-5 overflow-x-auto">
        <ul class="flex min-w-max gap-1 border-b border-default">
            @foreach($screens as $screen)
                <li>
                    <a
                        href="{{ route('crm.'.$screen) }}"
                        @if($screen === $entity) aria-current="page" @endif
                        class="flex min-h-touch items-center rounded-t-md px-4 text-md font-medium {{ $screen === $entity ? 'border-b-2 border-accent text-accent-text' : 'text-secondary hover:bg-surface-sunken hover:text-primary' }}"
                    >{{ __("crm.{$screen}.title") }}</a>
                </li>
            @endforeach
        </ul>
    </nav>

    @if($notice !== null)
        <p class="mt-5 rounded-md bg-success-surface px-3 py-2 text-sm text-success-text" role="status" data-crm-notice>{{ $notice }}</p>
    @endif

    @if($problem !== null)
        <p class="mt-5 rounded-md bg-danger-surface px-3 py-2 text-sm text-danger-text" role="alert" data-crm-problem>{{ $problem }}</p>
    @endif

    @if(! $hasWorkspace)
        {{-- pwa.md §2: a signed-in account with no membership sees the empty state, not a 403. --}}
        <x-empty-state :title="__('crm.no_workspace.title')" class="mt-6" data-crm-no-workspace>
            {{ __('crm.no_workspace.body') }}
        </x-empty-state>
    @else
        <div class="mt-6 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div class="flex w-full flex-col gap-3 sm:flex-row sm:items-end">
                <div class="w-full sm:max-w-xs">
                    <label for="crm-search" class="block text-sm font-medium text-primary">{{ __('crm.search.label') }}</label>
                    <input
                        id="crm-search"
                        type="search"
                        wire:model.live.debounce.400ms="search"
                        placeholder="{{ __('crm.search.placeholder') }}"
                        aria-describedby="crm-search-hint"
                        class="mt-1 block min-h-control-lg w-full rounded-md border border-strong bg-surface px-3 text-md text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2 focus-visible:ring-offset-surface"
                    >
                    <p id="crm-search-hint" class="mt-1 text-xs text-muted">{{ __('crm.search.hint') }}</p>
                </div>

                @if($statuses !== [])
                    <div class="w-full sm:max-w-45">
                        <label for="crm-status" class="block text-sm font-medium text-primary">{{ __('crm.fields.status') }}</label>
                        <select id="crm-status" wire:model.live="status" class="mt-1 block min-h-control-lg w-full rounded-md border border-strong bg-surface px-3 text-md text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2 focus-visible:ring-offset-surface">
                            <option value="">{{ __('crm.filters.any_status') }}</option>
                            @foreach($statuses as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                @if(in_array($entity, ['projects', 'tasks'], true))
                    <div class="w-full sm:max-w-60">
                        <label for="crm-customer" class="block text-sm font-medium text-primary">{{ __('crm.fields.customer') }}</label>
                        <select id="crm-customer" wire:model.live="customer" class="mt-1 block min-h-control-lg w-full rounded-md border border-strong bg-surface px-3 text-md text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2 focus-visible:ring-offset-surface">
                            <option value="">{{ __('crm.filters.any_customer') }}</option>
                            @foreach($customerOptions as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                <label class="flex min-h-touch items-center gap-2 text-md text-secondary">
                    <input type="checkbox" wire:model.live="showDeleted" class="size-5 rounded-sm border-strong text-accent focus-visible:ring-2 focus-visible:ring-focus">
                    {{ __('crm.filters.show_deleted') }}
                </label>

                @if($filtered)
                    <button type="button" wire:click="clearFilters" class="btn-secondary">{{ __('crm.filters.clear') }}</button>
                @endif
            </div>

            <button type="button" wire:click="startCreate" class="btn-primary shrink-0" data-crm-new>{{ __("crm.{$entity}.new") }}</button>
        </div>

        @if($formOpen)
            <section class="card mt-6" aria-labelledby="crm-form-title" data-crm-form>
                <h2 id="crm-form-title" class="text-lg font-semibold">
                    {{ $editing === null ? __("crm.{$entity}.new") : __('crm.actions.edit') }}
                </h2>

                <form wire:submit="save" class="mt-4 space-y-4" novalidate>
                    @include('crm.partials.form-'.$entity)

                    <div class="flex flex-wrap gap-3 pt-2">
                        <button type="submit" wire:loading.attr="disabled" wire:target="save" class="btn-primary">
                            <span wire:loading.remove wire:target="save">{{ __('crm.actions.save') }}</span>
                            <span wire:loading wire:target="save">{{ __('crm.actions.saving') }}</span>
                        </button>
                        <button type="button" wire:click="closePanels" class="btn-secondary">{{ __('crm.actions.cancel') }}</button>
                    </div>
                </form>
            </section>
        @endif

        @if($record !== null)
            @include('crm.partials.detail')
        @endif

        @if($page->total() === 0)
            <x-empty-state :title="$emptyTitle" class="mt-6">
                {{ $emptyBody }}

                @if(! $filtered)
                    <x-slot:action>
                        <button type="button" wire:click="startCreate" class="btn-primary">{{ __("crm.{$entity}.new") }}</button>
                    </x-slot:action>
                @endif
            </x-empty-state>
        @else
            <div class="mt-6 overflow-x-auto rounded-lg border border-default">
                @include('crm.partials.rows-'.$entity)
            </div>

            @include('crm.partials.pager')
        @endif
    @endif
</div>
