<?php

declare(strict_types=1);

namespace App\Browser\ComputerUse\Exceptions;

/**
 * Why the automation helper could not act right now (ADR-005 D1.2, D1.4, D2).
 * `ScreenLocked` and `DisplayAsleep` are the manual-unlock cases: the session is
 * paused, the user is asked to unlock, and the app never types the password.
 */
enum AutomationUnavailableReason: string
{
    case HelperNotRunning = 'helper_not_running';
    case BinaryVerificationFailed = 'binary_verification_failed';
    case PermissionMissing = 'permission_missing';
    case ScreenLocked = 'screen_locked';
    case DisplayAsleep = 'display_asleep';
    case NotConsoleUser = 'not_console_user';
}
