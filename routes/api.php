<?php

declare(strict_types=1);

use App\Http\Controllers\Health\LiveController;
use App\Http\Controllers\Health\ReadyController;
use App\Http\Controllers\Ipc\AiPauseController;
use App\Http\Controllers\Ipc\AiResumeController;
use App\Http\Controllers\Ipc\HealthController;
use App\Http\Controllers\Ipc\HeartbeatController;
use App\Http\Controllers\Ipc\LatestBriefController;
use App\Http\Controllers\Ipc\OAuthCallbackController;
use App\Http\Controllers\Ipc\PendingApprovalsController;
use App\Http\Controllers\Ipc\RemoteAccessDisableController;
use App\Http\Controllers\Ipc\RemoteAccessEnableController;
use App\Http\Controllers\Ipc\RemoteAccessStatusController;
use App\Http\Controllers\Ipc\ReservedController;
use App\Http\Middleware\LoopbackOnly;
use App\Security\Ipc\Middleware\IpcAuthenticate;
use App\Security\Ipc\Middleware\IpcRequireElevation;
use App\Security\Ipc\Middleware\IpcThrottle;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Loopback API routes
|--------------------------------------------------------------------------
|
| Registered without the `/api` prefix (bootstrap/app.php) so the health
| and IPC contracts' paths are literal. /health/* is loopback-only and
| excluded from any Tailscale Serve allowlist (health contract §6).
|
| /health/live needs no token (health contract §6). /health/ready and every
| /ipc/v1/* operation sit behind IpcAuthenticate (IPC contract §4.4 steps
| 1–7, scope as the parameter), then the §9 rate limit, then §7.3 elevation
| where required; the controller validates the body and runs the §11 gate.
| tests/Security/IpcPolicyArchTest.php fails CI for a route that breaks
| this shape.
|
*/

Route::middleware(LoopbackOnly::class)->prefix('health')->name('health.')->group(function (): void {
    Route::get('live', LiveController::class)->name('live');
    Route::get('ready', ReadyController::class)
        ->middleware(IpcAuthenticate::class.':menubar,supervisor')
        ->name('ready');
});

Route::prefix('ipc/v1')->name('ipc.')->group(function (): void {
    Route::get('health', HealthController::class)
        ->middleware([IpcAuthenticate::class.':menubar,supervisor', IpcThrottle::class.':health'])
        ->name('health');

    Route::middleware(IpcAuthenticate::class.':menubar')->group(function (): void {
        Route::get('briefs/latest', LatestBriefController::class)
            ->middleware(IpcThrottle::class.':briefs-latest')
            ->name('briefs.latest');

        Route::get('approvals', PendingApprovalsController::class)
            ->middleware(IpcThrottle::class.':approvals')
            ->name('approvals.pending');

        Route::post('ai/pause', AiPauseController::class)
            ->middleware(IpcThrottle::class.':ai-pause')
            ->name('ai.pause');

        Route::post('ai/resume', AiResumeController::class)
            ->middleware(IpcThrottle::class.':ai-resume')
            ->name('ai.resume');

        Route::post('heartbeat', HeartbeatController::class)
            ->middleware(IpcThrottle::class.':heartbeat')
            ->name('heartbeat');

        Route::post('oauth/callback', OAuthCallbackController::class)
            ->middleware(IpcThrottle::class.':oauth-callback')
            ->name('oauth.callback');

        Route::get('remote-access', RemoteAccessStatusController::class)
            ->middleware(IpcThrottle::class.':remote-access')
            ->name('remoteAccess.status');

        Route::post('remote-access/enable', RemoteAccessEnableController::class)
            ->middleware([IpcThrottle::class.':remote-access-enable', IpcRequireElevation::class.':remote-access-enable'])
            ->name('remoteAccess.enable');

        Route::post('remote-access/disable', RemoteAccessDisableController::class)
            ->middleware(IpcThrottle::class.':remote-access-disable')
            ->name('remoteAccess.disable');

        // §6.8: services are not HTTP operations; everything else is a signed 404.
        Route::any('{reserved}', ReservedController::class)->where('reserved', '.*')->name('reserved');
    });
});
