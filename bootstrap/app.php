<?php

use App\Http\Middleware\RedirectToCanonicalHost;
use App\Http\Middleware\ResolveRequestOrigin;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\TrustLoopbackHosts;
use App\Support\Runtime\RuntimeLayout;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // ADR-006 D3, threat model T-18: only loopback host names are trusted, so a spoofed
        // Host header (DNS rebinding, another local process) is refused before
        // ResolveRequestOrigin derives the URL root from it. The exact tailnet DNS name
        // joins the list when remote access is enabled (E4-1); never a wildcard. Our own
        // subclass, because the stock TrustHosts is inert in `local` and under tests.
        $middleware->prepend(TrustLoopbackHosts::class);

        // ADR-006 D3 (per-request origin, session.secure) first, then the one browser host on
        // loopback: a browser request on `127.0.0.1` is sent to the same path on `localhost`,
        // where a passkey can exist at all. Both run before StartSession, so nothing is signed
        // in on the non-canonical host. Only the `web` group — `/ipc/*` and `/health/*` are in
        // the `api` group and keep the literal `127.0.0.1` (ipc-contract §1.1). A16
        // browser-hardening headers wrap every web response. S1-04's app shell ships inside all
        // of them.
        $middleware->web(
            prepend: [ResolveRequestOrigin::class, RedirectToCanonicalHost::class],
            append: [SecurityHeaders::class],
        );

        $middleware->redirectGuestsTo('/login');
        $middleware->redirectUsersTo('/home');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // ADR-003 D7 row 2: nothing credential-shaped is ever flashed back into the session.
        $exceptions->dontFlash([
            'password',
            'password_confirmation',
            'current_password',
            'code',
            'client_secret',
            'token',
        ]);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('health/*') || $request->is('ipc/*') || $request->expectsJson(),
        );
    })->create();

// ADR-001 D4: when the Supervisor passes LEA_DATA_DIR, storage_path() and database_path()
// resolve into the mutable data directory while base_path() stays in the immutable runtime.
RuntimeLayout::apply($app);

return $app;
