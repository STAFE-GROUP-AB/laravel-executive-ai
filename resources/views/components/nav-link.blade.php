@props(['href', 'active' => false, 'variant' => 'side'])
@php
    $base = $variant === 'tab'
        ? 'flex min-h-touch flex-col items-center justify-center gap-0.5 px-2 py-2 text-xs font-medium transition-colors duration-fast ease-standard'
        : 'flex min-h-touch items-center gap-3 rounded-md px-3 text-md font-medium transition-colors duration-fast ease-standard';
    $state = $active
        ? ($variant === 'tab' ? 'text-accent' : 'bg-accent-subtle text-accent-text')
        : 'text-secondary hover:bg-surface-sunken hover:text-primary';
@endphp
<a href="{{ $href }}" @if($active) aria-current="page" @endif {{ $attributes->merge(['class' => "$base $state"]) }}>
    {{ $icon ?? '' }}
    <span>{{ $slot }}</span>
</a>
