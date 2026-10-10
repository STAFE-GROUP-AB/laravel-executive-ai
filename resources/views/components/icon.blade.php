@props(['name'])
{{-- Decorative stroke icons; the adjacent text is the label. --}}
<svg {{ $attributes->merge(['class' => 'size-6 shrink-0']) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
    @switch($name)
        @case('home')
            <path d="M3 11.5 12 4l9 7.5" />
            <path d="M5.5 10.5V20h13v-9.5" />
            <path d="M10 20v-5h4v5" />
            @break
        @case('briefs')
            <path d="M7 3.5h10a1.5 1.5 0 0 1 1.5 1.5v14A1.5 1.5 0 0 1 17 20.5H7A1.5 1.5 0 0 1 5.5 19V5A1.5 1.5 0 0 1 7 3.5Z" />
            <path d="M8.5 8.5h7M8.5 12h7M8.5 15.5h4" />
            @break
        @case('warning')
            <path d="M12 4.5 20.5 19H3.5L12 4.5Z" />
            <path d="M12 10v4M12 16.75v.25" />
            @break
        @case('error')
            <circle cx="12" cy="12" r="8.5" />
            <path d="M9 9l6 6M15 9l-6 6" />
            @break
        @case('offline')
            <path d="M4 8.5a12 12 0 0 1 16 0M7 12a8 8 0 0 1 10 0M10 15.5a4 4 0 0 1 4 0" />
            <path d="M12 19.25v.25M4 4l16 16" />
            @break
        @default
            <circle cx="12" cy="12" r="8.5" />
    @endswitch
</svg>
