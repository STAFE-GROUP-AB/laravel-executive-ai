<?php

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
        //
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
