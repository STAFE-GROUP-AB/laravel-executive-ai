<?php

declare(strict_types=1);

namespace App\Browser\ComputerUse;

/**
 * The only action kinds the automation helper will perform (ADR-005 D1.3: `click`,
 * `type`, `press`, `scroll`; `set_value`/`select_text` are forbidden except on
 * explicitly allowlisted elements and are not part of v1's contract).
 */
enum UiActionKind: string
{
    case Click = 'click';
    case Type = 'type';
    case Press = 'press';
    case Scroll = 'scroll';
}
