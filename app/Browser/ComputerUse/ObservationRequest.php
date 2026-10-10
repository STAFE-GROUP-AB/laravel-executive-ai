<?php

declare(strict_types=1);

namespace App\Browser\ComputerUse;

/**
 * What `observe()` should capture. The target must be on the session's allowlist;
 * the provider verifies that itself (ADR-005 D1.5: Swift and PHP each check,
 * both fail closed).
 *
 * @see ComputerUseProvider::observe()
 */
final readonly class ObservationRequest
{
    /**
     * @param  list<ObservationKind>  $kinds
     */
    public function __construct(
        public string $bundleId,
        public ?string $windowTitle,
        public array $kinds,
    ) {}
}
