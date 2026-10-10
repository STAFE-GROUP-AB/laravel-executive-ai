<?php

declare(strict_types=1);

namespace App\Browser\ComputerUse;

/**
 * What `observe()` may return: a redacted screenshot, the accessibility tree of the
 * target window, or both (ADR-005 D1.6: observation is covered by the session grant).
 */
enum ObservationKind: string
{
    case Screenshot = 'screenshot';
    case AccessibilityTree = 'accessibility_tree';
}
