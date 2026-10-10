<?php

declare(strict_types=1);

namespace App\Browser\ComputerUse;

/**
 * One `browser_sessions.allowlist` entry (ADR-005 D1.5): an app bundle id and an
 * optional window-title pattern. v1 ships exactly one default, Safari. The hard
 * denylist (login window, System Settings, password managers, terminals, secure
 * text fields) is enforced in code on top of this list and cannot be allowlisted.
 */
final readonly class AllowlistEntry
{
    public function __construct(
        public string $bundleId,
        public ?string $windowTitlePattern = null,
    ) {}
}
