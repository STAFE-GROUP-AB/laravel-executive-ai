<?php

use App\Http\Middleware\ResolveRequestOrigin;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\TrustLoopbackHosts;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
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

        // ADR-006 D3 (per-request origin, session.secure) first; A16 browser-hardening
        // headers around every web response. S1-04's app shell ships inside both.
        $middleware->web(prepend: [ResolveRequestOrigin::class], append: [SecurityHeaders::class]);

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
