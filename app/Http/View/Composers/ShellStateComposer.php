<?php

declare(strict_types=1);

namespace App\Http\View\Composers;

use App\Http\View\ShellState;
use App\Security\Authorization\Passkeys\PasskeyAuthenticator;
use App\Security\Privacy\PrivacyModeStore;
use App\Support\Health\AiReadiness;
use App\Support\Health\PendingApprovals;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\View\View;

/**
 * Binds `$shell` on layouts.app. Deliberately cheap: it reads the AI readiness
 * and approval bindings directly instead of running the seven /health/ready
 * subchecks on every page view.
 */
final class ShellStateComposer
{
    public function __construct(
        private readonly AiReadiness $aiReadiness,
        private readonly PendingApprovals $pendingApprovals,
        private readonly PasskeyAuthenticator $passkeys,
        private readonly Guard $auth,
        private readonly PrivacyModeStore $privacyModes,
    ) {}

    public function compose(View $view): void
    {
        $ai = $this->aiReadiness->report();

        $view->with('shell', new ShellState(
            aiState: $ai->state,
            aiProvider: $ai->provider,
            pendingApprovals: max(0, $this->pendingApprovals->count()),
            privacyMode: $this->privacyMode(),
            email: $this->auth->user()?->getAttribute('email'),
            passkeys: $this->passkeys->status(),
        ));
    }

    /** The stored policy, so the shell cannot claim a stricter mode than the one in force (T-68). */
    private function privacyMode(): string
    {
        return $this->privacyModes->active()->configValue();
    }
}
