<?php

declare(strict_types=1);

use App\Http\Controllers\Pwa\BriefsController;
use App\Http\Controllers\Pwa\HomeController;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/*
|--------------------------------------------------------------------------
| PWA routes (S1-04, blueprint §1.2)
|--------------------------------------------------------------------------
|
| Required from routes/web.php after routes/briefs.php (S1-03's preview
| routes, same `briefs.` name prefix, no overlap). The service worker treats
| every path registered here as network-only: nothing under /home or /briefs
| is ever written to a cache (docs/architecture/pwa.md). S1-02 owns /login,
| /logout and the `auth` session design; the pages here only require an
| authenticated user, and reading a brief goes through BriefPolicy.
|
| `{brief}` is a version row id or a document id (BriefCatalog::resolve), so
| the mail's "Open the full brief" link and the menu bar's latest_brief.id
| both land on the newest version.
|
*/

Route::middleware('auth')->group(function (): void {
    Route::get('/home', HomeController::class)->name('home');
    Route::get('/briefs', [BriefsController::class, 'index'])->name('briefs');
    Route::get('/briefs/{brief}', [BriefsController::class, 'show'])->name('briefs.show');
    Route::get('/briefs/{brief}/parity.json', [BriefsController::class, 'parity'])->name('briefs.parity');
});

// The one page the service worker precaches. No session, so the response never
// carries Set-Cookie and the cached copy holds no per-user state.
Route::get('/offline', fn () => view('offline'))
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class, AddQueuedCookiesToResponse::class])
    ->name('offline');
