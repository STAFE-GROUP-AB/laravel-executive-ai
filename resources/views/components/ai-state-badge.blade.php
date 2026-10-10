@use('App\Http\View\AiStateCopy')
@props(['state'])
{{-- Compact header indicator. The wording comes from AiStateCopy only (health contract §6). --}}
<x-badge :tone="AiStateCopy::tone($state)" data-ai-state="{{ $state->value }}" role="status" aria-label="{{ AiStateCopy::badgeLabel($state) }}">
    <span class="size-1.5 rounded-full bg-current" aria-hidden="true"></span>
    {{ AiStateCopy::label($state) }}
</x-badge>
