@use('App\Http\View\AiStateCopy')
@use('App\Support\Health\AiState')
@props(['state'])
{{--
    "AI setup required" / "AI error" banner (health contract §6, blueprint §0, §12.2). Rendered
    only while ai_state != ready; a banner, not an error. It never claims AI runs anywhere but
    the Mac (§1.2).
--}}
@if(AiStateCopy::showsBanner($state))
    @php
        $tone = AiStateCopy::tone($state) === 'danger'
            ? 'bg-danger-surface text-danger-text'
            : 'bg-warning-surface text-warning-text';
    @endphp
    <section {{ $attributes->merge(['class' => "rounded-lg p-4 sm:p-5 $tone"]) }} role="status" aria-labelledby="ai-state-title" data-ai-state-banner="{{ $state->value }}">
        <div class="flex gap-3">
            <x-icon :name="$state === AiState::Error ? 'error' : 'warning'" class="mt-0.5 size-6 shrink-0" />
            <div class="min-w-0">
                <h2 id="ai-state-title" class="text-md font-semibold">{{ AiStateCopy::label($state) }}</h2>
                <p class="mt-1 text-sm">{{ AiStateCopy::description($state) }}</p>
            </div>
        </div>
    </section>
@endif
