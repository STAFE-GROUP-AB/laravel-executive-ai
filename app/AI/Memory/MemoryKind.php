<?php

declare(strict_types=1);

namespace App\AI\Memory;

/**
 * What kind of thing a `memories` row remembers. `Instruction` is the one that
 * changes future model behaviour, which is why no row of this kind may reach
 * `Active` without a review (`MemoryStatus`, blueprint §4).
 */
enum MemoryKind: string
{
    case Profile = 'profile';
    case Preference = 'preference';
    case Fact = 'fact';
    case Instruction = 'instruction';
    case Summary = 'summary';
}
