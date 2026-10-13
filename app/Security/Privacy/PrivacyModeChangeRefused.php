<?php

declare(strict_types=1);

namespace App\Security\Privacy;

use RuntimeException;

/**
 * A privacy-mode change was refused for a reason that is not "no fresh proof of
 * presence" — that one is `ReauthenticationRequired`, so the two are
 * distinguishable at the catch site and the UI can send the owner to the right
 * next step.
 */
final class PrivacyModeChangeRefused extends RuntimeException
{
    /** Only the workspace owner may change the mode (OPEN-QUESTIONS A18). */
    public const string NOT_OWNER = 'not_owner';

    /** A widening change needs the owner to confirm it in the UI, not only to submit it. */
    public const string CONFIRMATION_REQUIRED = 'confirmation_required';

    /** `LOCAL_ONLY_ALLOW_LAN` has nothing to widen outside `LOCAL_ONLY`. */
    public const string LAN_TOGGLE_UNSUPPORTED = 'lan_toggle_unsupported';

    /** There is no workspace to attach the policy to yet. */
    public const string NO_WORKSPACE = 'no_workspace';

    public function __construct(public readonly string $reason)
    {
        parent::__construct("The privacy mode change was refused ({$reason}).");
    }
}
