<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pwa;

use App\AI\Providers\SupportedProviders;
use App\Http\Controllers\Controller;
use App\Security\Authorization\Gates\Abilities;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;

/**
 * /settings/ai — the PWA's view of SELECT_AI (blueprint §12.3 screen 5, §12.2
 * "Select AI banner"). Read-only: the choice itself is made on the Mac, where
 * the Supervisor can store a key (ADR-003). It lists only providers a real
 * adapter supports (§1.1) and preselects nothing. Network-only in the service
 * worker like every page under routes/pwa.php.
 */
final class AiSettingsController extends Controller
{
    public function __construct(private readonly SupportedProviders $providers) {}

    public function __invoke(): View
    {
        Gate::authorize(Abilities::VIEW_AI_SETTINGS);

        return view('settings.ai', [
            'providers' => $this->providers->supported(),
        ]);
    }
}
