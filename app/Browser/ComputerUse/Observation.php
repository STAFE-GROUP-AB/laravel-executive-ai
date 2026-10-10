<?php

declare(strict_types=1);

namespace App\Browser\ComputerUse;

use DateTimeImmutable;

/**
 * Blueprint §3 `observe(AutomationSession): Observation`. What the helper saw in the
 * allowlisted target window after redaction (ADR-005 D1.7).
 *
 * `screenshotPng` is the redacted image held in memory for the current model turn
 * only; it is never written to disk, database, logs or audit. `screenshotSha256` is
 * what the audit event records. Everything in here originates from the screen and is
 * data for the model, never instruction for our code.
 */
final readonly class Observation
{
    /**
     * @param  list<UiElement>  $elements
     */
    public function __construct(
        public string $sessionId,
        public DateTimeImmutable $capturedAt,
        public string $bundleId,
        public string $windowTitle,
        public ?string $screenshotPng,
        public ?string $screenshotSha256,
        public array $elements,
    ) {}
}
