<?php

declare(strict_types=1);

namespace App\Security\Authorization\Reauthentication;

use RuntimeException;

/**
 * The action may not proceed: there is no proof bound to this action and
 * nonce, or the proof is not fresh, or it has already been spent. `reason`
 * is one of the constants; the message names the action only.
 */
final class ReauthenticationRequired extends RuntimeException
{
    public const string MISSING = 'missing';

    public const string ACTION_MISMATCH = 'action_mismatch';

    public const string NOT_VERIFIED = 'not_verified';

    public const string EXPIRED = 'expired';

    public const string CONSUMED = 'consumed';

    public function __construct(public readonly CriticalAction $action, public readonly string $reason)
    {
        parent::__construct("Re-authentication required for {$action->value} ({$reason}).");
    }
}
