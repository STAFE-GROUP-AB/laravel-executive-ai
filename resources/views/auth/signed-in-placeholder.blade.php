{{--
    Interim page behind the `auth` middleware for /home and /briefs until S1-04
    ships the app shell and its views (resources/views/home, resources/views/briefs).
    It exists so the login flow has somewhere to land and so logout can be tested.
--}}
@component('layouts.auth', ['title' => $title])
    <section class="rounded-lg border border-default bg-surface p-6 shadow-1 sm:p-8">
        <h1 class="text-xl font-semibold text-primary">{{ $title }}</h1>
        <p class="mt-2 text-sm text-secondary">Signed in as <span class="font-medium text-primary">{{ auth()->user()?->email }}</span>. The {{ strtolower($title) }} view arrives with the app shell (S1-04).</p>

        <form method="POST" action="{{ route('logout') }}" class="mt-6">
            @csrf
            <button type="submit" class="inline-flex min-h-touch items-center justify-center rounded-md border border-strong bg-surface px-4 text-md font-medium text-primary hover:bg-surface-sunken focus:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2 focus-visible:ring-offset-surface">Sign out</button>
        </form>
    </section>
@endcomponent
