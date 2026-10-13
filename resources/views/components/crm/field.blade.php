@props([
    'name',
    'label',
    'type' => 'text',
    'hint' => null,
    'required' => false,
    'options' => null,
    'placeholder' => null,
    'rows' => 4,
    'blankOption' => null,
])
{{--
    One labelled form control (S2-09). The accessibility is the reason it is a
    component rather than copy-and-pasted markup: the `for`/`id` pair, the
    `aria-describedby` to the hint and to the error, `aria-invalid` while the
    field is wrong, and the 44 px minimum height are then right in one place
    instead of in forty. `wire:model.blur` rather than `.live`, so typing in a
    text field does not round-trip per keystroke.

    `$name` is the Livewire property path (`form.display_name`). Dots are legal
    in an id but awkward in a selector, so the id is the dotted path with dashes.
--}}
@php
    $id = str_replace(['.', '*'], '-', $name);
    $hasError = $errors->has($name);
    $describedBy = array_values(array_filter([
        $hint === null ? null : $id.'-hint',
        $hasError ? $id.'-error' : null,
    ]));
    $control = 'mt-1 block min-h-control-lg w-full rounded-md border bg-surface px-3 text-md text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2 focus-visible:ring-offset-surface '
        .($hasError ? 'border-danger-text' : 'border-strong');
@endphp

<div>
    <label for="{{ $id }}" class="block text-sm font-medium text-primary">
        {{ $label }}
        @if($required)
            <span class="text-danger-text">*</span>
            <span class="sr-only">(required)</span>
        @endif
    </label>

    @if($type === 'textarea')
        <textarea
            id="{{ $id }}"
            wire:model.blur="{{ $name }}"
            rows="{{ $rows }}"
            @if($required) required @endif
            @if($hasError) aria-invalid="true" @endif
            @if($describedBy !== []) aria-describedby="{{ implode(' ', $describedBy) }}" @endif
            class="{{ $control }} py-2"
        ></textarea>
    @elseif($type === 'select')
        <select
            id="{{ $id }}"
            wire:model.live="{{ $name }}"
            @if($required) required @endif
            @if($hasError) aria-invalid="true" @endif
            @if($describedBy !== []) aria-describedby="{{ implode(' ', $describedBy) }}" @endif
            class="{{ $control }}"
        >
            @if($blankOption !== null)
                <option value="">{{ $blankOption }}</option>
            @endif
            @foreach(($options ?? []) as $value => $text)
                <option value="{{ $value }}">{{ $text }}</option>
            @endforeach
        </select>
    @else
        <input
            id="{{ $id }}"
            type="{{ $type }}"
            wire:model.blur="{{ $name }}"
            @if($placeholder !== null) placeholder="{{ $placeholder }}" @endif
            @if($required) required @endif
            @if($hasError) aria-invalid="true" @endif
            @if($describedBy !== []) aria-describedby="{{ implode(' ', $describedBy) }}" @endif
            class="{{ $control }}"
        >
    @endif

    @if($hint !== null)
        <p id="{{ $id }}-hint" class="mt-1 text-xs text-muted">{{ $hint }}</p>
    @endif

    @error($name)
        <p id="{{ $id }}-error" class="mt-1 text-sm text-danger-text" role="alert">{{ $message }}</p>
    @enderror
</div>
