<?php

declare(strict_types=1);

namespace App\Briefs;

enum ItemKind: string
{
    case Action = 'action';
    case Followup = 'followup';
    case Risk = 'risk';
    case Info = 'info';
    case Commitment = 'commitment';
}
