<?php

declare(strict_types=1);

namespace App\Browser\ComputerUse;

/**
 * Blueprint §3 `capabilities(): array`, typed. `available` is false whenever the
 * helper is absent, the pinned binary fails verification or a permission is missing;
 * none of that may block install or CRM/AI chat (§6).
 */
final readonly class AutomationCapabilities
{
    /**
     * @param  list<UiActionKind>  $actions
     * @param  list<ObservationKind>  $observations
     */
    public function __construct(
        public bool $available,
        public string $providerName,
        public ?string $providerVersion,
        public AutomationPermissions $permissions,
        public array $actions,
        public array $observations,
    ) {}
}
