{{--
    Pagination, written here rather than taken from the framework's Tailwind
    view: that view carries its own palette (`bg-white`, `text-gray-700`) and
    design-system.md §4 allows tokens only, so it would be the one unstyled
    element on the page.

    Previous/Next rather than numbered pages, with the range stated in words so
    a screen reader gets the position without counting links. `wire:click` on
    the paginator's own methods, so the page number lives in the query string
    and a reload keeps it.

    Expects: $page.
--}}
@if($page->hasPages())
    <nav class="mt-4 flex flex-wrap items-center justify-between gap-3" aria-label="{{ __('crm.pagination.summary', ['from' => $page->firstItem(), 'to' => $page->lastItem(), 'total' => $page->total()]) }}">
        <p class="text-sm text-secondary" data-crm-pagination>
            {{ __('crm.pagination.summary', ['from' => $page->firstItem(), 'to' => $page->lastItem(), 'total' => $page->total()]) }}
        </p>

        <div class="flex gap-2">
            <button
                type="button"
                wire:click="previousPage"
                @disabled($page->onFirstPage())
                class="btn-secondary disabled:cursor-not-allowed disabled:opacity-60"
            >{{ __('crm.pagination.previous') }}</button>

            <button
                type="button"
                wire:click="nextPage"
                @disabled(! $page->hasMorePages())
                class="btn-secondary disabled:cursor-not-allowed disabled:opacity-60"
            >{{ __('crm.pagination.next') }}</button>
        </div>
    </nav>
@endif
