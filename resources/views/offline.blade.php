{{--
    The only page the service worker precaches (docs/architecture/pwa.md). Served without a
    session so the response carries no cookie and the cached copy holds no per-user state.
    Standalone on purpose: it must not depend on anything that is network-only.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <title>Offline · {{ config('app.name') }}</title>
    @include('partials.head')
</head>
<body class="min-h-dvh bg-canvas font-sans text-primary antialiased">
    <main id="main" class="pt-safe pb-safe mx-auto flex min-h-dvh w-full max-w-readable flex-col justify-center px-6 py-10">
        <div class="card">
            <div class="flex items-center gap-3">
                <x-icon name="offline" class="size-7 text-muted" />
                <h1 class="text-2xl font-semibold">You are offline</h1>
            </div>
            <p class="mt-4 text-md text-secondary">
                The app shell is available, but briefs, approvals and your CRM are never kept in the shell cache: they are read live from the core running on your Mac. Reconnect to continue.
            </p>
            <p class="mt-6">
                <a href="/home" class="btn-primary">Try again</a>
            </p>
        </div>
    </main>
</body>
</html>
