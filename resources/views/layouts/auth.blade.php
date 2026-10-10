{{--
    Standalone layout for the unauthenticated surfaces (/login, /install/owner)
    and the interim signed-in placeholder. S1-04 owns layouts/app.blade.php;
    this one deliberately has no navigation. Tokens only (design-system.md §4);
    the CSP nonce reaches @vite and Livewire through Vite::useCspNonce().
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="light dark">
    <title>{{ isset($title) ? $title.' · '.config('app.name') : config('app.name') }}</title>
    @if(file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif
    @livewireStyles
</head>
<body class="min-h-screen bg-canvas font-sans text-primary antialiased">
    <main class="mx-auto flex min-h-screen w-full max-w-readable flex-col justify-center px-4 py-8 sm:px-6">
        <p class="mb-4 text-xs font-medium uppercase tracking-wide text-muted">{{ config('app.name') }}</p>
        {{ $slot }}
    </main>
    @livewireScripts
</body>
</html>
