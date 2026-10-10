@use('App\Briefs\Render\ItemPresentation')
@extends('layouts.app')

@section('title', 'Brief v'.$page->payload['version'])

@section('content')
    @php
        $payload = $page->payload;
        $view = $page->view;
        $locale = $page->locale;
    @endphp

    <nav aria-label="Breadcrumb" class="text-sm text-secondary">
        <ol class="flex flex-wrap items-center gap-2">
            <li><a href="{{ route('briefs') }}" class="inline-flex min-h-touch items-center text-accent">Briefs</a></li>
            <li aria-hidden="true">/</li>
            <li aria-current="page">Version {{ $payload['version'] }}</li>
        </ol>
    </nav>

    <article lang="{{ $payload['language'] }}" data-brief data-brief-id="{{ $page->rowId }}" data-brief-document-id="{{ $payload['id'] }}" data-brief-version="{{ $payload['version'] }}" data-brief-generated-at="{{ $payload['generated_at'] }}" data-brief-content-hash="{{ $payload['content_hash'] }}" data-brief-language="{{ $payload['language'] }}" data-parity-hash="{{ $page->parityHash() }}">
        <header class="mt-4">
            <div class="flex flex-wrap items-center gap-2">
                <x-badge tone="neutral" data-brief-version-badge>{{ $view['version_label'] }}</x-badge>
                <span lang="en"><x-brief-state-badge :state="$page->state()" /></span>
                <span lang="en"><x-badge :tone="ItemPresentation::deliveryStateTone($page->deliveryState)" data-delivery-state="{{ $page->deliveryState }}">{{ ItemPresentation::deliveryStateLabel($page->deliveryState) }}</x-badge></span>
                @if($page->placeholder)
                    <span lang="en"><x-badge tone="info">Placeholder</x-badge></span>
                @endif
            </div>

            <h1 class="mt-3 text-2xl font-semibold" data-brief-subject>{{ $payload['subject'] }}</h1>
            <div class="mt-3 max-w-readable space-y-2 text-md text-secondary" data-brief-summary>
                @foreach($view['summary_paragraphs'] as $paragraph)
                    <p>{{ $paragraph }}</p>
                @endforeach
            </div>

            <dl class="mt-6 grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-muted">{{ __('brief.generated_at', [], $locale) }}</dt>
                    <dd class="mt-0.5"><time datetime="{{ $payload['generated_at'] }}" data-generated-at>{{ $view['generated_at_label'] }}</time></dd>
                </div>
                <div>
                    <dt class="text-muted">{{ __('brief.period', [], $locale) }}</dt>
                    <dd class="mt-0.5">
                        <time datetime="{{ $payload['period_start'] }}" class="sr-only">{{ $payload['period_start'] }}</time>
                        <time datetime="{{ $payload['period_end'] }}" class="sr-only">{{ $payload['period_end'] }}</time>
                        <span aria-hidden="true">{{ $view['period_label'] }}</span>
                    </dd>
                </div>
                <div lang="en">
                    <dt class="text-muted">Stale after</dt>
                    <dd class="mt-0.5"><time datetime="{{ $page->staleAfter }}">{{ $page->staleAfterLabel }}</time></dd>
                </div>
                <div lang="en">
                    <dt class="text-muted">Language</dt>
                    <dd class="mt-0.5 uppercase">{{ $payload['language'] }}</dd>
                </div>
                <div>
                    <dt class="text-muted">{{ __('brief.privacy_mode', [], $locale) }}</dt>
                    <dd class="mt-0.5" data-privacy-mode="{{ $payload['privacy_mode'] }}">{{ $view['privacy_mode_label'] }} ({{ $payload['privacy_mode'] }})</dd>
                </div>
                <div lang="en">
                    <dt class="text-muted">Model</dt>
                    <dd class="mt-0.5">{{ $page->placeholder ? 'None (placeholder)' : "{$page->provider} · {$page->model}" }}</dd>
                </div>
                <div>
                    <dt class="text-muted">{{ __('brief.brief_id', [], $locale) }}</dt>
                    <dd class="mt-0.5 font-mono text-xs break-all text-secondary">{{ $payload['id'] }}</dd>
                </div>
                <div lang="en">
                    <dt class="text-muted">Content hash</dt>
                    <dd class="mt-0.5 font-mono text-xs break-all text-secondary">{{ $payload['content_hash'] }}</dd>
                </div>
            </dl>
        </header>

        <section aria-labelledby="items-heading" class="mt-8">
            <h2 id="items-heading" class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('brief.items', [], $locale) }} ({{ count($page->items()) }})</h2>

            @if($page->hasItems())
                <ol class="mt-3 space-y-4" data-brief-items>
                    @foreach($page->items() as $item)
                        <x-brief-item :item="$item" :locale="$locale" />
                    @endforeach
                </ol>
            @elseif($page->placeholder)
                <x-empty-state title="Nothing yet" class="mt-3" lang="en">
                    This is the install-time placeholder: no model has run, so there are no items. Your first real Executive Brief arrives after AI setup on your Mac.
                </x-empty-state>
            @else
                <x-empty-state :title="__('brief.nothing_new_title', [], $locale)" class="mt-3">
                    {{ __('brief.nothing_new_body', [], $locale) }}
                </x-empty-state>
            @endif
        </section>
    </article>
@endsection
