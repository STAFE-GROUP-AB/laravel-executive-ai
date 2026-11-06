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
        @case('crm')
            <path d="M4 19.5V8l5-3 5 3 5-3v14.5" />
            <path d="M9 5v14.5M14 8v11.5" />
            @break
        @case('customers')
            <path d="M4.5 19.5V7.5l6-3 6 3v12" />
            <path d="M8 11h2M8 14.5h2M13 11h2M13 14.5h2" />
            <path d="M3 19.5h18" />
            @break
        @case('contacts')
            <circle cx="12" cy="9" r="3" />
            <path d="M5.5 19.5a6.5 6.5 0 0 1 13 0" />
            @break
        @case('projects')
            <path d="M4 7.5h6l1.5 2H20v9.5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7.5Z" />
            <path d="M4 7.5V6a1 1 0 0 1 1-1h4" />
            @break
        @case('tasks')
            <path d="M5 7.5h14M5 12h14M5 16.5h9" />
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
