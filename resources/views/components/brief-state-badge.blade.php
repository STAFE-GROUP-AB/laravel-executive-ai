@use('App\Support\Health\BriefState')
@props(['state'])
{{-- fresh / stale / error marker (health contract §6); colour never the only signal — the word is the signal. --}}
@php
    [$tone, $label] = match ($state) {
        BriefState::Fresh => ['success', 'Fresh'],
        BriefState::Stale => ['warning', 'Stale'],
        BriefState::Error => ['danger', 'Generation error'],
        BriefState::None => ['neutral', 'No brief yet'],
    };
@endphp
<x-badge :tone="$tone" data-brief-state="{{ $state->value }}">{{ $label }}</x-badge>
