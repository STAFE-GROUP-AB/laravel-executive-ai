@props(['tone' => 'neutral'])
@php
    $classes = match ($tone) {
        'accent' => 'bg-accent-subtle text-accent-text',
        'success' => 'bg-success-surface text-success-text',
        'warning' => 'bg-warning-surface text-warning-text',
        'danger' => 'bg-danger-surface text-danger-text',
        'info' => 'bg-info-surface text-info-text',
        default => 'bg-surface-sunken text-secondary',
    };
@endphp
<span {{ $attributes->merge(['class' => "inline-flex items-center gap-1 rounded-sm px-2 py-0.5 text-xs font-medium $classes"]) }}>{{ $slot }}</span>
