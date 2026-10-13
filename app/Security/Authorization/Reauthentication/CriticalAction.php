<?php

declare(strict_types=1);

namespace App\Security\Authorization\Reauthentication;

/**
 * The actions that need fresh proof of presence in the PWA (blueprint §11,
 * threat model T-71). Approvals are only ever granted here, never over IPC;
 * remote-access enable and key rotation have a Supervisor-side elevation for
 * the menu bar (ipc-contract §7.3) and this primitive for the PWA. A new
 * critical action is a new case plus a `ReauthRequiredTest` case.
 */
enum CriticalAction: string
{
    case ApproveOutboundAction = 'approvals.approve';

    case EnableRemoteAccess = 'remote-access.enable';

    case RotateKeys = 'keys.rotate';

    /**
     * Widening the privacy mode, or turning `LOCAL_ONLY_ALLOW_LAN` on
     * (OPEN-QUESTIONS A18, threat model T-68). Tightening it is not a critical
     * action: a control that makes it laborious to become stricter is a control
     * that gets left loose.
     */
    case ChangePrivacyMode = 'privacy.mode_change';

    public function label(): string
    {
        return __('security.reauth.actions.'.$this->value);
    }
}
