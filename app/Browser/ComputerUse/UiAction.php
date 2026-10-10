<?php

declare(strict_types=1);

namespace App\Browser\ComputerUse;

/**
 * The *content* of a proposed UI action, as the model proposed it and as frozen in
 * `action_proposals`. On its own this carries no authority: only an
 * AuthorizedUiAction, produced by the policy layer after approval, can be executed.
 *
 * `text` is typed into the target as-is and is never logged (ADR-005 D1.10); the
 * proposal stores its hash. `keyChord` uses the helper's key names (e.g. `cmd+l`).
 */
final readonly class UiAction
{
    public function __construct(
        public UiActionKind $kind,
        public ?string $elementId = null,
        public ?Rect $target = null,
        public ?string $text = null,
        public ?string $keyChord = null,
        public ?int $scrollDeltaX = null,
        public ?int $scrollDeltaY = null,
    ) {}
}
