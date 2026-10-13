<?php

declare(strict_types=1);

namespace App\AI\Memory;

/**
 * Lifecycle of a `memories` row. Blueprint §4: "LLM kan inte uppdatera permanent
 * minne utan annoterad, granskad operation" — a row the model proposes is written
 * `Proposed` and only a reviewed operation moves it to `Active`, which is the only
 * status the agent reads. `memories.active_marker` is generated from this column, so
 * a second active row for the same key is unrecordable rather than merely wrong.
 */
enum MemoryStatus: string
{
    case Proposed = 'proposed';
    case Active = 'active';
    case Superseded = 'superseded';
    case Rejected = 'rejected';

    public function isReadableByAgent(): bool
    {
        return $this === self::Active;
    }

    public function requiresReview(): bool
    {
        return $this === self::Proposed;
    }
}
