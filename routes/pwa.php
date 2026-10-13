<?php

declare(strict_types=1);

use App\Http\Controllers\Pwa\AiSettingsController;
use App\Http\Controllers\Pwa\BriefsController;
use App\Http\Controllers\Pwa\HomeController;
use App\Http\Controllers\Pwa\SecuritySettingsController;
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
    // S1-11: the "Select AI" banner's target until the deep link into the Mac's SELECT_AI exists.
    Route::get('/settings/ai', AiSettingsController::class)->name('settings.ai');

    // S2-05: passkeys and signed-in devices (docs/architecture/auth.md §5, §7).
    // Never cached by the service worker, like every page in this file.
    Route::get('/settings/security', SecuritySettingsController::class)
        ->middleware('cache.headers:no_store')
        ->name('settings.security');
});

// The one page the service worker precaches. No session, so the response never
// carries Set-Cookie and the cached copy holds no per-user state.
Route::get('/offline', fn () => view('offline'))
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class, AddQueuedCookiesToResponse::class])
    ->name('offline');
