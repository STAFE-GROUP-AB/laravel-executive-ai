<?php

declare(strict_types=1);

use App\Http\Controllers\Health\LiveController;
use App\Http\Controllers\Health\ReadyController;
use App\Http\Middleware\LoopbackOnly;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Loopback API routes
|--------------------------------------------------------------------------
|
| Registered without the `/api` prefix (bootstrap/app.php) so the health
| contract's paths are literal. /health/* is loopback-only, excluded from
| any Tailscale Serve allowlist and from the PWA's public routes (health
| contract §6). S1-08 adds /ipc/v1/* and the IPC token on /health/ready.
|
*/

Route::middleware(LoopbackOnly::class)->prefix('health')->name('health.')->group(function (): void {
    Route::get('live', LiveController::class)->name('live');
    Route::get('ready', ReadyController::class)->name('ready');
});
