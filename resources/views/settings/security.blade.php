@extends('layouts.app')

@section('title', __('security.settings.title'))

@section('content')
    <h1 class="text-2xl font-semibold">{{ __('security.settings.title') }}</h1>
    <p class="mt-2 max-w-readable text-md text-secondary">{{ __('security.settings.lead') }}</p>

    <div class="mt-6 space-y-6">
        <livewire:auth.passkeys />
        <livewire:auth.devices />
    </div>
@endsection
