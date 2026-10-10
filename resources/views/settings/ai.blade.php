@use('App\Http\View\AiStateCopy')
@use('App\Support\Health\AiState')
@extends('layouts.app')

@section('title', __('ai.settings.title'))

@section('content')
    <h1 class="text-2xl font-semibold">{{ __('ai.settings.title') }}</h1>
    <p class="mt-2 max-w-readable text-md text-secondary">{{ __('ai.settings.lead') }}</p>

    <x-ai-state-banner :state="$shell->aiState" :action="false" class="mt-5" />

    <section aria-labelledby="ai-current-heading" class="card mt-6">
        <h2 id="ai-current-heading" class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('ai.settings.current_heading') }}</h2>
        <p class="mt-3 text-md" data-ai-current-provider="{{ $shell->aiProvider ?? '' }}">
            @if($shell->aiProvider === null)
                {{ __('ai.settings.none_selected') }}
            @elseif($shell->aiState === AiState::Ready)
                {{ __('ai.settings.current_ready', ['provider' => AiStateCopy::providerName($shell->aiProvider)]) }}
            @else
                {{ __('ai.settings.current', ['provider' => AiStateCopy::providerName($shell->aiProvider)]) }}
            @endif
        </p>
    </section>

    <section aria-labelledby="ai-options-heading" class="mt-8">
        <h2 id="ai-options-heading" class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('ai.settings.options_heading') }}</h2>

        {{-- A list, not a form: nothing is selectable here and nothing is preselected (§12.3 screen 5). --}}
        <ul role="list" class="mt-3 space-y-3" data-ai-options data-ai-preselected="none">
            <li class="card" data-ai-option="skip">
                <p class="text-md font-semibold">{{ __('ai.settings.skip_title') }}</p>
                <p class="mt-1 text-sm text-secondary">{{ __('ai.settings.skip_detail') }}</p>
            </li>
            @forelse($providers as $provider)
                <li class="card" data-ai-option="{{ $provider->id }}" data-ai-kind="{{ $provider->kind->value }}">
                    <p class="text-md font-semibold">{{ AiStateCopy::providerName($provider->id) }}</p>
                    <p class="mt-1 text-sm text-secondary">{{ __('ai.settings.kinds.'.$provider->kind->value) }}</p>
                </li>
            @empty
                <li class="card" data-ai-option-none>
                    <p class="text-sm text-secondary">{{ __('ai.settings.none_available') }}</p>
                </li>
            @endforelse
        </ul>

        <p class="mt-4 max-w-readable text-sm text-muted">{{ __('ai.settings.where') }}</p>
    </section>
@endsection
