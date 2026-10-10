<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\LogoutController;
use App\Http\Middleware\EnsureNoOwnerExists;
use App\Livewire\Auth\Login;
use App\Livewire\Install\CreateOwnerForm;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Authentication routes (S1-02, docs/architecture/auth.md)
|--------------------------------------------------------------------------
|
| Required from routes/web.php, so everything here runs inside the `web`
| group (session, CSRF, ResolveRequestOrigin, SecurityHeaders). Login is a
| one-time code by email; there is no password or password-reset route.
| The unauthenticated pages are served with Cache-Control: no-store. The
| signed-in surface (/home, /briefs) lives in routes/pwa.php (S1-04) under
| the route names `home` and `briefs` that the login redirect relies on.
|
*/

Route::middleware('cache.headers:no_store')->group(function (): void {
    Route::get('/install/owner', CreateOwnerForm::class)
        ->middleware(EnsureNoOwnerExists::class)
        ->name('install.owner');

    Route::get('/login', Login::class)
        ->middleware('guest')
        ->name('login');

    Route::post('/logout', LogoutController::class)
        ->middleware('auth')
        ->name('logout');
});
