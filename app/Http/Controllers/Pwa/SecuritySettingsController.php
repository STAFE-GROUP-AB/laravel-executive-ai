<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pwa;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

/**
 * /settings/security — passkeys and signed-in devices (S2-05,
 * docs/architecture/auth.md §5, §7). The page is the app shell around the two
 * Livewire components; every action lives in them.
 */
final class SecuritySettingsController extends Controller
{
    public function __invoke(): View
    {
        return view('settings.security');
    }
}
