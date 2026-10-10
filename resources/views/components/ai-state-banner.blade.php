@use('App\Http\View\AiStateCopy')
@use('App\Support\Health\AiState')
@props(['state', 'action' => true])
{{--
    "AI setup required" / "AI error" banner (health contract §6, blueprint §0, §12.2). Rendered
    only while ai_state != ready; a banner, not an error. It never claims AI runs anywhere but
    the Mac (§1.2). The "Select AI" affordance (§12.2) points at the AI settings page until the
    deep link into the Mac's SELECT_AI screen exists; `:action="false"` hides it on that page.
--}}
@if(AiStateCopy::showsBanner($state))
    @php
        $tone = AiStateCopy::tone($state) === 'danger'
            ? 'bg-danger-surface text-danger-text'
            : 'bg-warning-surface text-warning-text';
        $actionLabel = $action && Route::has('settings.ai') ? AiStateCopy::actionLabel($state) : null;
    @endphp
    <section {{ $attributes->merge(['class' => "rounded-lg p-4 sm:p-5 $tone"]) }} role="status" aria-labelledby="ai-state-title" data-ai-state-banner="{{ $state->value }}">
        <div class="flex gap-3">
            <x-icon :name="$state === AiState::Error ? 'error' : 'warning'" class="mt-0.5 size-6 shrink-0" />
            <div class="min-w-0">
                <h2 id="ai-state-title" class="text-md font-semibold">{{ AiStateCopy::label($state) }}</h2>
                <p class="mt-1 text-sm">{{ AiStateCopy::description($state) }}</p>
                @if($actionLabel !== null)
                    <a href="{{ route('settings.ai') }}" class="btn-primary mt-4 inline-flex" data-ai-select>{{ $actionLabel }}</a>
                @endif
            </div>
        </div>
    </section>
@endif
