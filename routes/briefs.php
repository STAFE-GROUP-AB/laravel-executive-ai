<?php

declare(strict_types=1);

use App\Http\Controllers\Briefs\BriefPreviewController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Briefs (blueprint §1.2, §1.3; S1-03)
|--------------------------------------------------------------------------
|
| Required from routes/web.php. Everything here is behind the `auth`
| middleware (S1-02 owns the OTP login and the guest redirect) and the
| BriefPolicy, which scopes a brief to the organizations the user belongs to.
|
| The two preview routes return the exact mail parts BriefMailRenderer
| produces. They read rendered strings; no mailer is involved, so they cannot
| dispatch mail (§1.3 "lokalt preview-läge utan utgående mejl", §5.5).
|
*/

Route::middleware('auth')->prefix('briefs')->name('briefs.')->group(function (): void {
    Route::get('{brief}/preview/mail', [BriefPreviewController::class, 'html'])->name('previewMail');
    Route::get('{brief}/preview/text', [BriefPreviewController::class, 'text'])->name('previewText');
});
