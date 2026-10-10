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
| The unauthenticated pages are served with Cache-Control: no-store.
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

/*
| The PWA's signed-in surface. S1-04 replaces the two closures below with its
| own components and views (resources/views/home, resources/views/briefs); the
| paths, the route names and the `auth` middleware are the contract it builds on.
*/
Route::middleware('auth')->group(function (): void {
    Route::get('/home', fn () => view('auth.signed-in-placeholder', ['title' => 'Home']))->name('home');
    Route::get('/briefs', fn () => view('auth.signed-in-placeholder', ['title' => 'Briefs']))->name('briefs');
});
