<?php

declare(strict_types=1);

namespace App\Browser\ComputerUse;

use DateTimeImmutable;

/**
 * Blueprint §3 `execute(...): ActionResult`. Always returned, even on denial, so the
 * agent loop can show the model what happened without the helper raising for
 * expected outcomes. `message` is user-facing and contains no screen content.
 */
final readonly class ActionResult
{
    public function __construct(
        public string $proposalId,
        public ActionOutcome $outcome,
        public DateTimeImmutable $startedAt,
        public DateTimeImmutable $finishedAt,
        public ?string $message,
        public ?Observation $observationAfter,
    ) {}

    public function succeeded(): bool
    {
        return $this->outcome === ActionOutcome::Executed;
    }
}
