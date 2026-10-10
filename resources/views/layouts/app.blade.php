{{--
    PWA app shell (S1-04, blueprint §1.2, design-system.md). Sidebar from 1024px, bottom tab bar
    below it, safe-area insets on iPhone, skip link, one <main>. `$shell` comes from
    App\Http\View\Composers\ShellStateComposer. No inline scripts or <style> elements: the pages
    run under S1-02's strict CSP (App\Http\Middleware\SecurityHeaders; threat-model T-21/T-22, A16).
    Any future inline bootstrap must carry nonce="{{ Vite::cspNonce() }}".
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <title>@hasSection('title')@yield('title') · @endif{{ config('app.name') }}</title>
    @include('partials.head')
</head>
<body class="min-h-dvh bg-canvas font-sans text-primary antialiased">
    <a href="#main" class="skip-link">Skip to content</a>

    <div class="lg:flex lg:min-h-dvh">
        <aside class="hidden lg:sticky lg:top-0 lg:flex lg:h-dvh lg:w-64 lg:shrink-0 lg:flex-col lg:border-r lg:border-default lg:bg-surface">
            <div class="flex min-h-touch items-center gap-3 px-6 pt-6 pb-4">
                <x-brand-mark class="size-8" />
                <span class="text-lg font-semibold">{{ config('app.name') }}</span>
            </div>

            <nav aria-label="Primary" class="flex-1 px-3">
                <ul class="space-y-1">
                    <li>
                        <x-nav-link :href="route('home')" :active="request()->routeIs('home')">
                            <x-slot:icon><x-icon name="home" /></x-slot:icon>
                            Home
                        </x-nav-link>
                    </li>
                    <li>
                        <x-nav-link :href="route('briefs')" :active="request()->routeIs('briefs.*')">
                            <x-slot:icon><x-icon name="briefs" /></x-slot:icon>
                            Briefs
                        </x-nav-link>
                    </li>
                </ul>
            </nav>

            <div class="space-y-3 border-t border-default px-6 py-4 text-sm text-secondary">
                @if($shell->email !== null)
                    <p>Signed in as <span class="font-medium break-all text-primary">{{ $shell->email }}</span></p>
                @endif
                <p>Privacy mode: <span class="font-medium text-primary">{{ __('brief.privacy_modes.'.$shell->privacyMode) }}</span></p>
                <p data-passkeys-available="{{ $shell->passkeys->available ? 'true' : 'false' }}">Passkeys: {{ $shell->passkeys->message }}@if($shell->passkeys->trackingUrl !== null) <a href="{{ $shell->passkeys->trackingUrl }}" rel="noopener noreferrer" class="text-accent">(issue)</a>@endif</p>
                <p class="text-xs text-muted">Core {{ config('app.version') }}. AI and the scheduler run on your Mac.</p>
                @if(Route::has('logout'))
                    <form method="post" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="btn-secondary w-full">Log out</button>
                    </form>
                @endif
            </div>
        </aside>

        <div class="flex min-w-0 flex-1 flex-col">
            <header class="pt-safe sticky top-0 z-sticky border-b border-default bg-canvas">
                <div class="mx-auto flex min-h-touch w-full max-w-content items-center justify-between gap-3 px-4 py-2 sm:px-6 lg:px-8">
                    <a href="{{ route('home') }}" class="flex min-h-touch items-center gap-2 rounded-md font-semibold lg:hidden">
                        <x-brand-mark class="size-7" />
                        <span>{{ config('app.name') }}</span>
                    </a>
                    <p class="hidden text-sm text-secondary lg:block">@hasSection('title')@yield('title')@else{{ config('app.name') }}@endif</p>

                    <div class="flex items-center gap-2">
                        @if($shell->pendingApprovals > 0)
                            <x-badge tone="accent" data-pending-approvals="{{ $shell->pendingApprovals }}">
                                {{ $shell->pendingApprovals }} awaiting approval
                            </x-badge>
                        @endif
                        <x-ai-state-badge :state="$shell->aiState" />
                        @if(Route::has('logout'))
                            <form method="post" action="{{ route('logout') }}" class="lg:hidden">
                                @csrf
                                <button type="submit" class="btn-secondary" aria-label="Log out">Log out</button>
                            </form>
                        @endif
                    </div>
                </div>
            </header>

            <main id="main" tabindex="-1" class="pb-tabbar mx-auto w-full max-w-content flex-1 px-4 py-6 sm:px-6 lg:px-8 lg:pb-12">
                @yield('content')
            </main>
        </div>
    </div>

    <nav aria-label="Primary" class="pb-safe fixed inset-x-0 bottom-0 z-sticky border-t border-default bg-surface lg:hidden">
        <ul class="grid grid-cols-2">
            <li>
                <x-nav-link variant="tab" :href="route('home')" :active="request()->routeIs('home')">
                    <x-slot:icon><x-icon name="home" /></x-slot:icon>
                    Home
                </x-nav-link>
            </li>
            <li>
                <x-nav-link variant="tab" :href="route('briefs')" :active="request()->routeIs('briefs.*')">
                    <x-slot:icon><x-icon name="briefs" /></x-slot:icon>
                    Briefs
                </x-nav-link>
            </li>
        </ul>
    </nav>
</body>
</html>
