@use('App\Briefs\Render\ItemPresentation')
@extends('layouts.app')

@section('title', 'Home')

@section('content')
    <h1 class="text-2xl font-semibold">Home</h1>

    <x-ai-state-banner :state="$shell->aiState" class="mt-5" />

    <section aria-labelledby="latest-brief-heading" class="mt-8">
        <h2 id="latest-brief-heading" class="text-xs font-medium tracking-wide text-muted uppercase">Latest brief</h2>

        @if($page !== null)
            @php
                $payload = $page->payload;
                $view = $page->view;
                $locale = $page->locale;
            @endphp
            <article class="card mt-3" lang="{{ $payload['language'] }}" data-latest-brief data-brief-id="{{ $page->rowId }}" data-brief-document-id="{{ $payload['id'] }}" data-brief-version="{{ $payload['version'] }}" data-brief-generated-at="{{ $payload['generated_at'] }}">
                <div class="flex flex-wrap items-center gap-2">
                    <span lang="en"><x-brief-state-badge :state="$briefState" /></span>
                    <x-badge tone="neutral">{{ $view['version_label'] }}</x-badge>
                    <span lang="en"><x-badge :tone="ItemPresentation::deliveryStateTone($page->deliveryState)">{{ ItemPresentation::deliveryStateLabel($page->deliveryState) }}</x-badge></span>
                    @if($page->placeholder)
                        <span lang="en"><x-badge tone="info">Placeholder</x-badge></span>
                    @endif
                </div>

                <h3 class="mt-3 text-lg font-semibold">{{ $payload['subject'] }}</h3>
                <div class="mt-2 space-y-2 text-md text-secondary">
                    @foreach($view['summary_paragraphs'] as $paragraph)
                        <p>{{ $paragraph }}</p>
                    @endforeach
                </div>

                <dl class="mt-5 grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-muted">{{ __('brief.generated_at', [], $locale) }}</dt>
                        <dd class="mt-0.5"><time datetime="{{ $payload['generated_at'] }}">{{ $view['generated_at_label'] }}</time></dd>
                    </div>
                    <div>
                        <dt class="text-muted">{{ __('brief.items', [], $locale) }}</dt>
                        <dd class="mt-0.5">{{ count($page->items()) }}</dd>
                    </div>
                    <div lang="en">
                        <dt class="text-muted">Stale after</dt>
                        <dd class="mt-0.5"><time datetime="{{ $page->staleAfter }}">{{ $page->staleAfterLabel }}</time></dd>
                    </div>
                    <div>
                        <dt class="text-muted">{{ __('brief.privacy_mode', [], $locale) }}</dt>
                        <dd class="mt-0.5">{{ $view['privacy_mode_label'] }}</dd>
                    </div>
                </dl>

                <div class="mt-6" lang="en">
                    <a href="{{ route('briefs.show', $page->rowId) }}" class="btn-primary">Open full brief</a>
                </div>
            </article>
        @else
            <x-empty-state title="No brief yet" class="mt-3">
                The core has not produced a brief for your organization. The install-time placeholder appears here as soon as it exists; your first real Executive Brief follows AI setup on your Mac.
            </x-empty-state>
        @endif
    </section>

    <div class="mt-8 grid gap-4 sm:grid-cols-2">
        <section aria-labelledby="approvals-heading" class="card">
            <h2 id="approvals-heading" class="text-xs font-medium tracking-wide text-muted uppercase">Approvals</h2>
            <p class="mt-3 text-xl font-semibold" data-pending-approvals-count>{{ $shell->pendingApprovals }}</p>
            <p class="mt-1 text-sm text-secondary">
                {{ $shell->pendingApprovals === 1 ? 'action awaiting your approval.' : 'actions awaiting your approval.' }}
                Nothing is sent, deleted or written anywhere without an approval here.
            </p>
        </section>

        <section aria-labelledby="quick-links-heading" class="card">
            <h2 id="quick-links-heading" class="text-xs font-medium tracking-wide text-muted uppercase">Quick links</h2>
            <ul class="mt-3 divide-y divide-default">
                <li>
                    <a href="{{ route('briefs') }}" class="flex min-h-touch items-center justify-between gap-3 rounded-md text-md font-medium text-accent">
                        Brief history
                        <span aria-hidden="true">→</span>
                    </a>
                </li>
                @if($page !== null)
                    <li>
                        <a href="{{ route('briefs.show', $page->rowId) }}" class="flex min-h-touch items-center justify-between gap-3 rounded-md text-md font-medium text-accent">
                            Latest brief, version {{ $page->payload['version'] }}
                            <span aria-hidden="true">→</span>
                        </a>
                    </li>
                    @if(Route::has('briefs.previewMail'))
                        <li>
                            <a href="{{ route('briefs.previewMail', $page->rowId) }}" class="flex min-h-touch items-center justify-between gap-3 rounded-md text-md font-medium text-accent">
                                Preview the brief mail (nothing is sent)
                                <span aria-hidden="true">→</span>
                            </a>
                        </li>
                    @endif
                @endif
                <li class="flex min-h-touch items-center justify-between gap-3 text-md text-muted">
                    Assistant, CRM and approvals
                    <span class="text-xs">Sprint 2</span>
                </li>
            </ul>
        </section>
    </div>
@endsection
