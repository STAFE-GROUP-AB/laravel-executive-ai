<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pwa;

use App\Http\Controllers\Controller;
use App\Security\Authorization\Gates\Abilities;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;

/**
 * /settings/security — passkeys and signed-in devices (S2-05,
 * docs/architecture/auth.md §5, §7). The page is the app shell around the two
 * Livewire components; every action lives in them, and each of those actions
 * runs its own ability — the page's `viewSecuritySettings` is not a licence
 * for the actions inside it (S2-06).
 */
final class SecuritySettingsController extends Controller
{
    public function __invoke(): View
    {
        Gate::authorize(Abilities::VIEW_SECURITY_SETTINGS);

        return view('settings.security');
    }
}
