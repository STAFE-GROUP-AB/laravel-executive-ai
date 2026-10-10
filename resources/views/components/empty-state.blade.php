@props(['title'])
<div {{ $attributes->merge(['class' => 'rounded-lg border border-dashed border-strong bg-surface px-6 py-10 text-center']) }} data-empty-state>
    <h3 class="text-lg font-semibold">{{ $title }}</h3>
    <div class="mx-auto mt-2 max-w-readable text-secondary">{{ $slot }}</div>
    @isset($action)
        <div class="mt-5 flex justify-center">{{ $action }}</div>
    @endisset
</div>
