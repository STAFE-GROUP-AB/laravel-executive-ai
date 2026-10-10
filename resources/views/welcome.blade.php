<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }}</title>
    @if(file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif
</head>
<body class="min-h-screen bg-canvas text-primary antialiased">
    <main class="mx-auto max-w-readable p-8">
        <h1 class="text-2xl font-semibold">{{ config('app.name') }}</h1>
        <p class="mt-2 text-secondary">Core {{ config('app.version') }}. The application shell is running; nothing is configured yet.</p>
    </main>
</body>
</html>
