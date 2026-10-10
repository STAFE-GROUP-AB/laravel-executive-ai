@use('App\Briefs\Render\ItemPresentation')
@props(['item', 'locale'])
{{--
    One brief item from BriefViewModel::build() — the same item array the mail prints (brief
    schema §6): kind, title, whole body, priority, due date, and its source references or its
    uncertainty marker (§4.2), both when both exist. Labels come from lang/*/brief.php in the
    brief's own locale. The enum values travel as data attributes so parity checks read data,
    not wording. Nothing here reads the model or the raw document.
--}}
<li data-brief-item data-item-id="{{ $item['id'] }}" data-position="{{ $item['number'] - 1 }}" data-kind="{{ $item['kind'] }}" data-priority="{{ $item['priority'] }}">
    <article class="rounded-lg bg-surface p-4 shadow-1 sm:p-5">
        <header class="flex flex-wrap items-center gap-2">
            <x-badge tone="neutral">{{ $item['kind_label'] }}</x-badge>
            <x-badge :tone="ItemPresentation::priorityTone($item['priority'])">{{ $item['priority_label'] }}</x-badge>
            @if($item['due_at'] !== null)
                <span class="text-sm text-secondary">{{ __('brief.due', [], $locale) }}: <time datetime="{{ $item['due_at'] }}" data-due-at>{{ $item['due_at_label'] }}</time></span>
            @endif
        </header>

        <h3 class="mt-3 text-lg font-semibold">{{ $item['number'] }}. {{ $item['title'] }}</h3>
        <div class="mt-2 space-y-2 text-md text-secondary" data-item-body>
            @foreach($item['body_paragraphs'] as $paragraph)
                <p>{{ $paragraph }}</p>
            @endforeach
        </div>

        @if($item['sources'] !== [])
            <div class="mt-4">
                <h4 class="text-xs font-medium tracking-wide text-muted uppercase">{{ trans_choice('brief.sources', count($item['sources']), [], $locale) }}</h4>
                <ul class="mt-2 divide-y divide-default rounded-md border border-default">
                    @foreach($item['sources'] as $source)
                        <li class="flex flex-col gap-1 px-3 py-2 text-sm" data-source-ref data-source-id="{{ $source['id'] }}" data-source-type="{{ $source['type'] }}">
                            <div class="flex flex-wrap items-center gap-2">
                                <x-badge tone="info">{{ $source['type_label'] }}</x-badge>
                                @if($source['title'] !== null)
                                    <span class="font-medium" data-source-title>{{ $source['title'] }}</span>
                                @endif
                            </div>
                            @if($source['account_label'] !== null)
                                <span class="text-secondary" data-source-account>{{ $source['account_label'] }}</span>
                            @endif
                            @if($source['href'] !== null)
                                <a href="{{ $source['href'] }}" rel="noopener noreferrer" class="inline-flex min-h-touch items-center font-mono text-xs break-all text-accent" data-source-uri>{{ $source['uri'] }}</a>
                            @else
                                <span class="font-mono text-xs break-all text-muted" data-source-uri>{{ $source['uri'] }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if($item['uncertainty'] !== null)
            <p class="mt-4 rounded-md bg-warning-surface p-3 text-sm text-warning-text" data-uncertainty data-confidence="{{ $item['uncertainty']['confidence'] }}">
                <strong>{{ __('brief.uncertainty', [], $locale) }}</strong>
                ({{ $item['uncertainty']['confidence_label'] }}):
                <span data-uncertainty-reason>{{ $item['uncertainty']['reason'] }}</span>
            </p>
        @endif

        @if($item['approvals'] !== [])
            <ul class="mt-4 flex flex-wrap gap-2">
                @foreach($item['approvals'] as $approval)
                    <li>
                        <x-badge tone="accent" data-action-id="{{ $approval['id'] }}" class="min-h-control-sm">{{ __('brief.approval_required', [], $locale) }}</x-badge>
                    </li>
                @endforeach
            </ul>
        @endif
    </article>
</li>
